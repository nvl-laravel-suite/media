<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Exceptions\ConversionFailedException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaImageTransformer;
use Nvl\Media\Services\MediaLocalFileMaterializer;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaTemporaryFileRegistry;
use Nvl\Media\Support\MediaVariationFileNamer;
use Throwable;

/**
 * Generates one revision-checked image variation under a shared media mutation lock.
 */
final class GenerateImageVariationAction
{
    /**
     * Create the image variation action.
     */
    public function __construct(
        private readonly MediaImageTransformer $imageTransformer,
        private readonly MediaFileEffectScheduler $fileEffects,
        private readonly MediaFileOperator $files,
        private readonly MediaLocalFileMaterializer $materializer,
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaPathResolver $pathResolver,
        private readonly MediaTemporaryFileRegistry $temporaryFiles,
    ) {}

    /**
     * Generate one variation when the media source revision is still current.
     *
     * @return MediaImageVariation|null Null for unsupported media or stale work
     */
    public function execute(
        Media $media,
        ConversionDefinition $definition,
        ?int $expectedRevision = null,
    ): ?MediaImageVariation {
        return $this->mutationLock->execute(
            $media->id,
            function () use ($media, $definition, $expectedRevision): ?MediaImageVariation {
                $currentMedia = Media::query()->find($media->id);

                if (! $currentMedia instanceof Media
                    || ! $currentMedia->isAvailable()
                    || ! $currentMedia->type->supportsConversions()) {
                    return null;
                }

                $sourceRevision = $expectedRevision ?? $currentMedia->revision;

                if ($currentMedia->revision !== $sourceRevision) {
                    $this->logStaleWork($currentMedia, $definition, $sourceRevision);

                    return null;
                }

                $definition->validate();
                DB::transaction(function () use ($currentMedia): void {
                    $currentMedia->forceFill([
                        'status' => MediaLifecycleStatus::ProcessingVariations,
                        'failure_code' => null,
                        'failure_context' => null,
                    ])->save();
                });

                $temporaryOutput = tempnam(sys_get_temp_dir(), 'media_var_');

                if ($temporaryOutput === false) {
                    throw new ConversionFailedException(
                        "Failed to create temporary file for variation [{$definition->name}].",
                    );
                }

                $this->temporaryFiles->track($temporaryOutput);
                $newVariationPath = null;

                try {
                    $resultExtension = $definition->getResultExtension($currentMedia->extension);
                    $quality = $definition->targetQuality ?? 80;
                    $existing = MediaImageVariation::query()
                        ->where('media_id', $currentMedia->id)
                        ->where('label', $definition->name)
                        ->first();
                    $existingPath = $existing?->getPath();
                    $sourceFile = $this->materializer->lease(
                        $currentMedia->disk,
                        $currentMedia->buildPath(),
                    );

                    try {
                        $result = $this->imageTransformer->process(
                            $sourceFile->path(),
                            $temporaryOutput,
                            $definition,
                        );
                    } finally {
                        $sourceFile->release();
                    }

                    $baseFilename = MediaVariationFileNamer::make(
                        $currentMedia->hash,
                        $definition->name,
                        $result['width'],
                        $result['height'],
                        $resultExtension,
                    );
                    $newFilename = pathinfo($baseFilename, PATHINFO_FILENAME)
                        .'-'.Str::random(16)
                        .'.'.$resultExtension;
                    $newVariationPath = $this->pathResolver->variationPath(
                        $currentMedia,
                        $newFilename,
                    );
                    $contents = file_get_contents($temporaryOutput);

                    if ($contents === false) {
                        throw new ConversionFailedException(
                            "Failed to read processed variation file for [{$definition->name}].",
                        );
                    }

                    if (! $this->files->put(
                        $currentMedia->disk,
                        $newVariationPath,
                        $contents,
                        $currentMedia->visibility,
                    )) {
                        throw new ConversionFailedException(
                            "Failed to store variation [{$definition->name}] on disk [{$currentMedia->disk}].",
                        );
                    }

                    try {
                        $variation = DB::transaction(function () use (
                            $currentMedia,
                            $definition,
                            $sourceRevision,
                            $result,
                            $resultExtension,
                            $quality,
                            $existing,
                            $newVariationPath,
                        ): ?MediaImageVariation {
                            $this->fileEffects->deleteAfterRollback(
                                $currentMedia->disk,
                                [$newVariationPath],
                                'variation_new_object',
                            );

                            $lockedMedia = Media::query()
                                ->lockForUpdate()
                                ->find($currentMedia->id);

                            if (! $lockedMedia instanceof Media
                                || ! $lockedMedia->isAvailable()
                                || $lockedMedia->revision !== $sourceRevision) {
                                return null;
                            }

                            $variation = MediaImageVariation::query()->updateOrCreate(
                                [
                                    'media_id' => $lockedMedia->id,
                                    'label' => $definition->name,
                                ],
                                [
                                    'storage_path' => $newVariationPath,
                                    'width' => $result['width'],
                                    'height' => $result['height'],
                                    'size' => $result['size'],
                                    'format' => $resultExtension,
                                    'quality' => $quality,
                                    'status' => MediaLifecycleStatus::Available->value,
                                    'source_revision' => $sourceRevision,
                                    'attempts' => $existing instanceof MediaImageVariation
                                        ? $existing->attempts + 1
                                        : 1,
                                    'failure_context' => null,
                                ],
                            );

                            $lockedMedia->forceFill([
                                'status' => MediaLifecycleStatus::Available,
                            ])->save();

                            return $variation;
                        });
                    } catch (Throwable $exception) {
                        $this->fileEffects->deleteNow(
                            $currentMedia->disk,
                            [$newVariationPath],
                            'variation_pre_commit_failure',
                        );

                        throw new ConversionFailedException(
                            "DB commit failed for variation [{$definition->name}] on media [{$currentMedia->id}]: {$exception->getMessage()}",
                            previous: $exception,
                        );
                    }

                    if (! $variation instanceof MediaImageVariation) {
                        $this->fileEffects->deleteNow(
                            $currentMedia->disk,
                            [$newVariationPath],
                            'variation_stale_object',
                        );
                        $this->logStaleWork($currentMedia, $definition, $sourceRevision);

                        return null;
                    }

                    if (is_string($existingPath) && $existingPath !== $newVariationPath) {
                        $this->fileEffects->deleteAfterCommit(
                            $currentMedia->disk,
                            [$existingPath],
                            'variation_superseded_object',
                        );
                    }

                    return $variation;
                } catch (ConversionFailedException $exception) {
                    $this->recordFailure($currentMedia, $definition, $exception);

                    throw $exception;
                } catch (Throwable $exception) {
                    if (is_string($newVariationPath)) {
                        $this->fileEffects->deleteNow(
                            $currentMedia->disk,
                            [$newVariationPath],
                            'variation_unexpected_failure',
                        );
                    }

                    $failure = new ConversionFailedException(
                        "Variation [{$definition->name}] failed for media [{$currentMedia->id}]: {$exception->getMessage()}",
                        previous: $exception,
                    );
                    $this->recordFailure($currentMedia, $definition, $failure);

                    throw $failure;
                } finally {
                    $this->temporaryFiles->release($temporaryOutput);
                }
            },
        );
    }

    /**
     * Persist bounded failure diagnostics while keeping the verified source usable.
     */
    private function recordFailure(
        Media $media,
        ConversionDefinition $definition,
        ConversionFailedException $exception,
    ): void {
        $context = [
            'variation' => $definition->name,
            'exception' => $exception::class,
            'message' => mb_substr($exception->getMessage(), 0, 1000),
        ];

        DB::transaction(function () use ($media, $definition, $context): void {
            Media::query()
                ->whereKey($media->id)
                ->where('status', MediaLifecycleStatus::ProcessingVariations->value)
                ->update([
                    'status' => MediaLifecycleStatus::Available,
                    'failure_code' => 'variation_failed',
                    'failure_context' => json_encode($context, JSON_THROW_ON_ERROR),
                ]);

            MediaImageVariation::query()
                ->where('media_id', $media->id)
                ->where('label', $definition->name)
                ->update([
                    'status' => MediaLifecycleStatus::Failed->value,
                    'failure_context' => json_encode($context, JSON_THROW_ON_ERROR),
                    'attempts' => DB::raw('attempts + 1'),
                ]);
        });
    }

    /**
     * Record a stale variation attempt as an expected concurrency outcome.
     */
    private function logStaleWork(
        Media $media,
        ConversionDefinition $definition,
        int $expectedRevision,
    ): void {
        Log::info('Media variation work became stale.', [
            'media_id' => $media->id,
            'variation' => $definition->name,
            'expected_revision' => $expectedRevision,
            'current_revision' => $media->revision,
        ]);
    }
}
