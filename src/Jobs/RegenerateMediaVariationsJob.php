<?php

declare(strict_types=1);

namespace Nvl\Media\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaConfiguredVariationService;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Support\MediaImageConfiguration;
use Nvl\Media\Support\MediaQueueConfiguration;
use Nvl\Media\Support\MediaQueueEnvelope;
use Nvl\Support\Facades\PackageLog;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Throwable;

/**
 * Batch-regenerates image variations for media records matching the given filter criteria.
 *
 * Each run handles one chunk of matching media, dispatching individual GenerateImageVariationJob
 * instances for each requested preset, then continues from its last id in a new job.
 * Supports filtering by type, disk, date range, and specific preset names.
 */
final class RegenerateMediaVariationsJob implements OwnershipQueuedJob, ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    private readonly OwnershipJobEnvelope $ownershipEnvelope;

    /** Carry scalar producer authority before native scheduling. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /**
     * Create a new batch regeneration job.
     *
     * @param  MediaType|null  $type  Filter by media type (null = images only by default)
     * @param  string|null  $disk  Filter by storage disk
     * @param  string|null  $createdAfter  Only process media created after this date (Y-m-d)
     * @param  string|null  $createdBefore  Only process media created before this date (Y-m-d)
     * @param  list<string>|null  $presetNames  Specific preset labels to regenerate (null = all enabled)
     * @param  int  $chunkSize  Number of records one job run processes before continuing in a new job
     * @param  string|null  $afterId  Continuation cursor: only process media after this id
     */
    public function __construct(
        private readonly ?MediaType $type = null,
        private readonly ?string $disk = null,
        private readonly ?string $createdAfter = null,
        private readonly ?string $createdBefore = null,
        private readonly ?array $presetNames = null,
        private readonly int $chunkSize = 500,
        ?OwnershipJobEnvelope $envelope = null,
        private readonly ?string $afterId = null,
    ) {
        $this->ownershipEnvelope = $envelope ?? MediaQueueEnvelope::ownership();
        $this->tries = MediaQueueConfiguration::jobInteger('regenerate', 'tries', 1);
        $this->timeout = MediaQueueConfiguration::jobInteger('regenerate', 'timeout', 60);
        $this->onQueue(MediaQueueConfiguration::name());
        $this->onConnection(MediaQueueConfiguration::connection());
    }

    /**
     * Execute the batch regeneration job.
     */
    public function handle(MediaConfiguredVariationService $configuredVariationService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $presets = $configuredVariationService->presetConfigs(
            names: $this->presetNames,
            enabledOnly: $this->presetNames === null,
        );

        if (empty($presets)) {
            PackageLog::log('media', 'info', 'nvl.media.variation_regeneration.skipped', [
                'type' => $this->type?->value,
                'disk' => $this->disk,
                'created_after' => $this->createdAfter,
                'created_before' => $this->createdBefore,
                'preset_names' => $this->presetNames,
            ], 'normal');

            return;
        }

        $query = $this->buildQuery();

        if ($this->afterId !== null) {
            $query->where('id', '>', $this->afterId);
        }

        $mediaChunk = $query->limit($this->chunkSize)->get();
        $processed = 0;

        foreach ($mediaChunk as $media) {
            if (! $media->type->supportsConversions() || ! MediaImageConfiguration::driverDecodes($media->extension)) {
                continue;
            }

            foreach ($presets as $name => $preset) {
                GenerateImageVariationJob::dispatch(
                    $media->id,
                    (string) $name,
                    $preset,
                    $media->revision,
                    $this->ownershipEnvelope,
                )->afterCommit();
            }

            $processed++;
        }

        PackageLog::log('media', 'info', 'nvl.media.variation_regeneration.dispatched', [
            'processed_count' => $processed,
            'type' => $this->type?->value,
            'disk' => $this->disk,
            'created_after' => $this->createdAfter,
            'created_before' => $this->createdBefore,
            'preset_names' => $this->presetNames,
        ], 'normal');

        $last = $mediaChunk->last();

        if ($last instanceof Media && $mediaChunk->count() === $this->chunkSize) {
            $this->continueAfter($last->id);
        }
    }

    /**
     * Hand the rest of the catalogue to a new job so one run stays within its timeout and a cancelled batch stops.
     */
    private function continueAfter(string $lastId): void
    {
        $next = (new self(
            $this->type,
            $this->disk,
            $this->createdAfter,
            $this->createdBefore,
            $this->presetNames,
            $this->chunkSize,
            $this->ownershipEnvelope,
            $lastId,
        ))->onConnection($this->connection)->onQueue($this->queue);

        $batch = $this->batch();

        if ($batch !== null) {
            $batch->add([$next]);

            return;
        }

        dispatch($next);
    }

    /**
     * Build the filtered query for matching media records.
     *
     * @return Builder<Media>
     */
    private function buildQuery(): Builder
    {
        $query = MediaModelRegistry::query()->available();

        // Default to images only (the only type that supports conversions)
        if ($this->type !== null) {
            $query->where('type', $this->type->value);
        } else {
            $query->where('type', MediaType::IMAGE->value);
        }

        if ($this->disk !== null) {
            $query->where('disk', $this->disk);
        }

        if ($this->createdAfter !== null) {
            $query->where('created_at', '>=', Carbon::parse($this->createdAfter)->startOfDay());
        }

        if ($this->createdBefore !== null) {
            $query->where('created_at', '<=', Carbon::parse($this->createdBefore)->endOfDay());
        }

        return $query->orderBy('id');
    }

    /**
     * Get the tags for queue monitoring and identification.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['media-regenerate-batch'];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return MediaQueueConfiguration::backoff('regenerate', [60]);
    }

    public function failed(?Throwable $exception): void
    {
        PackageLog::log('media', 'error', 'nvl.media.variation_regeneration.failed', [
            'exception' => $exception?->getMessage(),
        ], 'normal');
    }
}
