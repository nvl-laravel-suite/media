<?php

declare(strict_types=1);

namespace Nvl\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaConfiguredVariationService;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Support\MediaQueueConfiguration;
use Nvl\Media\Support\MediaQueueEnvelope;
use Nvl\Support\Facades\PackageLog;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Throwable;

/** ProcessMediaVariationsJob: dispatches all configured variation jobs for a freshly uploaded media record. */
final class ProcessMediaVariationsJob implements OwnershipQueuedJob, ShouldBeUnique, ShouldQueue, TenantQueuedJob
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public int $uniqueFor;

    private readonly TenantJobEnvelope $envelope;

    private readonly OwnershipJobEnvelope $ownershipEnvelope;

    /** Carry scalar producer authority before native scheduling. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    public function __construct(
        private readonly string $mediaId,
        private readonly bool $includeOutputConversion = true,
        ?TenantJobEnvelope $envelope = null,
    ) {
        $this->envelope = MediaQueueEnvelope::fallback($envelope);
        $this->ownershipEnvelope = MediaQueueEnvelope::ownership();
        $this->tries = MediaQueueConfiguration::jobInteger('dispatch', 'tries', 3);
        $this->timeout = MediaQueueConfiguration::jobInteger('dispatch', 'timeout', 60);
        $this->uniqueFor = MediaQueueConfiguration::jobInteger('dispatch', 'unique_for', 1800);
        $this->onQueue(MediaQueueConfiguration::name());
        $this->onConnection(MediaQueueConfiguration::connection());
    }

    /**
     * Execute the job.
     */
    public function handle(MediaConfiguredVariationService $configuredVariationService): void
    {
        $media = MediaModelRegistry::query()->find($this->mediaId);

        if ($media === null) {
            PackageLog::log('media', 'warning', 'nvl.media.variation_processing.missing', [
                'media_id' => $this->mediaId,
            ], 'normal');

            return;
        }

        if (! $media->type->supportsConversions()) {
            return;
        }

        foreach ($configuredVariationService->configuredQueuePayloadsFor(
            media: $media,
            includeOutputConversion: $this->includeOutputConversion,
            includePresets: (bool) config('nvl-media.auto_generate_variations', true),
        ) as $name => $preset) {
            GenerateImageVariationJob::dispatch(
                $media->id,
                $name,
                $preset,
                $media->revision,
                $this->envelope,
            )->afterCommit();
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['media-variations', "media:{$this->mediaId}"];
    }

    public function uniqueId(): string
    {
        if ($this->ownershipEnvelope->context()->mode !== OwnershipContextMode::Disabled) {
            return MediaQueueEnvelope::key($this->ownershipEnvelope, $this->mediaId);
        }

        return $this->envelope->context->mode === TenantContextMode::Disabled
            ? $this->mediaId
            : hash('sha256', serialize($this->envelope->context)).':'.$this->mediaId;
    }

    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return MediaQueueConfiguration::backoff('dispatch', [10, 30, 90]);
    }

    public function failed(?Throwable $exception): void
    {
        PackageLog::log('media', 'error', 'nvl.media.variation_processing.exhausted', [
            'media_id' => $this->mediaId,
            'exception' => $exception?->getMessage(),
        ], 'normal');
    }
}
