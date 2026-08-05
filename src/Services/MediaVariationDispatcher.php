<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaQueueConfiguration;
use Throwable;

/** MediaVariationDispatcher resolves and dispatches variation definitions for existing media records. */
final class MediaVariationDispatcher
{
    public function __construct(
        private readonly GenerateImageVariationAction $generateVariation,
        private readonly MediaConfiguredVariationService $configuredVariationService,
        private readonly MediaFileEffectScheduler $fileEffects,
        private readonly MediaVariationDefinitionNormalizer $definitionNormalizer,
    ) {}

    /**
     * Dispatch globally configured variations for a freshly uploaded media record.
     *
     * @param  Media  $media  Uploaded media record
     */
    public function dispatchConfiguredForUpload(Media $media): void
    {
        $definitions = array_replace(
            $this->collectConfiguredDefinitions($media),
            $this->collectUploadDefinitions($media),
        );

        $this->dispatchDefinitions($media, $definitions);
    }

    /**
     * Dispatch only missing global and upload-specific definitions for reused media.
     */
    public function dispatchMissingConfiguredForUpload(Media $media): void
    {
        $definitions = array_replace(
            $this->collectConfiguredDefinitions($media),
            $this->collectUploadDefinitions($media),
        );

        $this->dispatchDefinitions($media, $definitions, true);
    }

    /**
     * Dispatch only the variations that are missing for a specific associable collection.
     */
    public function dispatchMissingForAssociation(Media $media, Model&HasMedia $model, string $slotName): void
    {
        $definitions = array_replace(
            $this->collectConfiguredDefinitions($media),
            $this->collectDefinitionsForAssociation($media, $model, $slotName),
            $this->collectUploadDefinitions($media),
        );

        $this->dispatchDefinitions($media, $definitions, true);
    }

    /**
     * Regenerate all configured variations for the media's current state and associations.
     */
    public function dispatchForCurrentState(Media $media): void
    {
        if (! $media->type->supportsConversions()) {
            return;
        }

        /** @var array<string, ConversionDefinition> $definitions */
        $definitions = $this->collectConfiguredDefinitions($media);

        $media->loadMissing('associations.associable');

        foreach ($media->associations as $association) {
            $associable = $association->associable;

            if (! $associable instanceof HasMedia) {
                continue;
            }

            $slotName = data_get($association->metadata, 'slot');
            $resolvedSlotName = is_string($slotName) && $slotName !== ''
                ? $slotName
                : $association->collection;

            foreach ($this->collectDefinitionsForAssociation($media, $associable, $resolvedSlotName) as $name => $definition) {
                $definitions[$name] = $definition;
            }
        }

        $definitions = array_replace($definitions, $this->collectUploadDefinitions($media));

        $this->dispatchDefinitions($media, $definitions);
    }

    /**
     * Collect slot-level and model-level conversion definitions for one association.
     *
     * @return array<string, ConversionDefinition>
     */
    public function collectDefinitionsForAssociation(Media $media, Model&HasMedia $model, string $slotName): array
    {
        /** @var array<string, ConversionDefinition> $definitions */
        $definitions = [];

        $slotConfig = $model->getMediaSlot($slotName);

        if ($slotConfig instanceof MediaSlot) {
            foreach ($slotConfig->getConversionDefinitions() as $name => $definition) {
                $definitions[$name] = $definition;
            }
        }

        $model->registerMediaConversions($media);

        foreach ($model->getModelConversions() as $conversion) {
            if ($conversion->shouldBePerformedOn($slotName)) {
                $definitions[$conversion->name] = $conversion;
            }
        }

        return $definitions;
    }

    /**
     * Collect globally configured variation definitions for this media.
     *
     * @return array<string, ConversionDefinition>
     */
    private function collectConfiguredDefinitions(Media $media): array
    {
        return $this->configuredVariationService->configuredDefinitionsFor(
            media: $media,
            includePresets: (bool) config('media.auto_generate_variations', true),
        );
    }

    /**
     * Resolve definitions persisted specifically for this upload.
     *
     * @return array<string, ConversionDefinition>
     */
    private function collectUploadDefinitions(Media $media): array
    {
        return $this->definitionNormalizer->definitions($media->variation_definitions);
    }

    /**
     * Dispatch variation generation for the provided definitions.
     *
     * @param  array<string, ConversionDefinition>  $definitions
     */
    private function dispatchDefinitions(Media $media, array $definitions, bool $missingOnly = false): void
    {
        $this->fileEffects->afterCommit(function () use ($media, $definitions, $missingOnly): void {
            $this->dispatchDefinitionsNow($media, $definitions, $missingOnly);
        });
    }

    /**
     * Dispatch already-committed variation definitions.
     *
     * @param  array<string, ConversionDefinition>  $definitions
     */
    private function dispatchDefinitionsNow(
        Media $media,
        array $definitions,
        bool $missingOnly = false,
    ): void {
        if (! $media->isAvailable()
            || ! $media->type->supportsConversions()
            || empty($definitions)) {
            return;
        }

        if ($missingOnly) {
            $media->loadMissing('imageVariations');
            $existingLabels = $media->imageVariations->pluck('label')->all();

            $definitions = array_filter(
                $definitions,
                static fn (ConversionDefinition $definition): bool => $definition->enabled && ! in_array($definition->name, $existingLabels, true),
            );
        } else {
            $definitions = array_filter(
                $definitions,
                static fn (ConversionDefinition $definition): bool => $definition->enabled,
            );
        }

        if (empty($definitions)) {
            return;
        }

        $shouldQueue = MediaQueueConfiguration::enabled();

        foreach ($definitions as $definition) {
            try {
                if ($shouldQueue && $definition->shouldBeQueued) {
                    GenerateImageVariationJob::dispatch(
                        $media->id,
                        $definition->name,
                        $definition,
                        $media->revision,
                    )->afterCommit();

                    continue;
                }

                $this->generateVariation->execute($media, $definition);
            } catch (Throwable $e) {
                Log::warning("Variation dispatch [{$definition->name}] failed for media [{$media->id}]: {$e->getMessage()}");
            }
        }
    }
}
