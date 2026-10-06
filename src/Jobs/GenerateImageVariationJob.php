<?php

declare(strict_types=1);

namespace Nvl\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Models\Media;
use Nvl\Media\Support\MediaQueueConfiguration;
use Nvl\Media\Support\MediaQueueEnvelope;
use Nvl\Support\Facades\PackageLog;
use Nvl\Support\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Throwable;

/** GenerateImageVariationJob: queued generation of a single image variation for a media record. */
final class GenerateImageVariationJob implements ShouldBeUnique, ShouldQueue, TenantQueuedJob
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public int $uniqueFor;

    private readonly ConversionDefinition $definition;

    private readonly TenantJobEnvelope $envelope;

    /**
     * @param  array<string, mixed>|ConversionDefinition  $presetConfig
     */
    public function __construct(
        private readonly string $mediaId,
        private readonly string $presetName,
        array|ConversionDefinition $presetConfig,
        private readonly int $sourceRevision = 1,
        ?TenantJobEnvelope $envelope = null,
    ) {
        $this->envelope = MediaQueueEnvelope::fallback($envelope);
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

        return $this->envelope->context->mode === TenantContextMode::Disabled
            ? $identity
            : hash('sha256', serialize($this->envelope->context)).':'.$identity;
    }

    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }

    /**
     * Execute the job.
     */
    public function handle(GenerateImageVariationAction $action): void
    {
        $media = Media::find($this->mediaId);

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
            $action->execute($media, $this->definition, $this->sourceRevision);
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
