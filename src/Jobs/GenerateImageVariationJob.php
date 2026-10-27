<?php

declare(strict_types=1);

namespace Nvl\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaVariations;
use Nvl\Media\Support\MediaQueueConfiguration;
use Nvl\Media\Support\MediaQueueEnvelope;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Facades\PackageLog;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Throwable;

/** GenerateImageVariationJob: queued generation of a single image variation for a media record. */
final class GenerateImageVariationJob implements OwnershipQueuedJob, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public int $uniqueFor;

    public readonly ConversionDefinition $definition;

    private readonly OwnershipJobEnvelope $ownershipEnvelope;

    /** Carry scalar producer authority before native scheduling. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /**
     * @param  array<string, mixed>|ConversionDefinition  $presetConfig
     */
    public function __construct(
        public readonly string $mediaId,
        public readonly string $presetName,
        array|ConversionDefinition $presetConfig,
        public readonly int $sourceRevision = 1,
        ?OwnershipJobEnvelope $envelope = null,
    ) {
        $this->ownershipEnvelope = $envelope ?? MediaQueueEnvelope::ownership();
        $this->definition = $presetConfig instanceof ConversionDefinition
            ? $presetConfig
            : ConversionDefinition::fromPreset($presetName, $presetConfig);
        $this->tries = MediaQueueConfiguration::jobInteger('generate', 'tries', 3);
        $this->timeout = MediaQueueConfiguration::jobInteger('generate', 'timeout', 60);
        $this->uniqueFor = MediaQueueConfiguration::jobInteger('generate', 'unique_for', 1800);
        $this->onQueue($this->definition->queueName ?? MediaQueueConfiguration::name());
        $this->onConnection(MediaQueueConfiguration::connection());
    }

    /**
     * Return the queue uniqueness key for one media variation preset.
     */
    public function uniqueId(): string
    {
        $identity = $this->mediaId.':'.$this->sourceRevision.':'.$this->presetName;

        if ($this->ownershipEnvelope->context()->mode !== OwnershipContextMode::Disabled) {
            return MediaQueueEnvelope::key($this->ownershipEnvelope, $identity);
        }

        return $identity;
    }

    /**
     * Execute the job.
     */
    public function handle(MediaVariations $action): void
    {
        $media = MediaModelRegistry::query()->find($this->mediaId);

        if ($media === null) {
            PackageLog::log('media', 'warning', 'nvl.media.variation.missing', [
                'media_id' => $this->mediaId,
            ], 'normal');

            return;
        }

        if ($media->revision !== $this->sourceRevision) {
            PackageLog::log('media', 'info', 'nvl.media.variation.stale', [
                'media_id' => $this->mediaId,
                'expected_revision' => $this->sourceRevision,
                'current_revision' => $media->revision,
            ], 'normal');

            return;
        }

        if (! $media->isAvailable() || ! $media->type->supportsConversions()) {
            return;
        }

        try {
            $action->generate(SystemActor::Background, $media, $this->definition, $this->sourceRevision);
        } catch (Throwable $e) {
            PackageLog::log('media', 'warning', 'nvl.media.variation.failed', [
                'preset' => $this->presetName,
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
            ], 'normal');

            throw $e;
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['media-variation', "media:{$this->mediaId}", "preset:{$this->presetName}"];
    }

    /**
     * Return the configured retry delays in seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return MediaQueueConfiguration::backoff('generate', [10, 30, 90]);
    }

    /**
     * Record a terminal queue failure without exposing storage details.
     */
    public function failed(?Throwable $exception): void
    {
        PackageLog::log('media', 'error', 'nvl.media.variation.exhausted', [
            'media_id' => $this->mediaId,
            'preset' => $this->presetName,
            'exception' => $exception?->getMessage(),
        ], 'normal');
    }
}
