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
use Illuminate\Support\Facades\Log;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaConfiguredVariationService;
use Nvl\Media\Support\MediaQueueConfiguration;
use Throwable;

/**
 * Batch-regenerates image variations for media records matching the given filter criteria.
 *
 * Queries matching media in chunks and dispatches individual GenerateImageVariationJob
 * instances for each requested preset. Supports filtering by type, disk, date range,
 * and specific preset names.
 */
final class RegenerateMediaVariationsJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    /**
     * Create a new batch regeneration job.
     *
     * @param  MediaType|null  $type  Filter by media type (null = images only by default)
     * @param  string|null  $disk  Filter by storage disk
     * @param  string|null  $createdAfter  Only process media created after this date (Y-m-d)
     * @param  string|null  $createdBefore  Only process media created before this date (Y-m-d)
     * @param  list<string>|null  $presetNames  Specific preset labels to regenerate (null = all enabled)
     * @param  int  $chunkSize  Number of records per processing chunk
     */
    public function __construct(
        private readonly ?MediaType $type = null,
        private readonly ?string $disk = null,
        private readonly ?string $createdAfter = null,
        private readonly ?string $createdBefore = null,
        private readonly ?array $presetNames = null,
        private readonly int $chunkSize = 500,
    ) {
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
            Log::info('RegenerateMediaVariationsJob: no enabled presets found, skipping.', [
                'type' => $this->type?->value,
                'disk' => $this->disk,
                'created_after' => $this->createdAfter,
                'created_before' => $this->createdBefore,
                'preset_names' => $this->presetNames,
            ]);

            return;
        }

        $processed = 0;

        $this->buildQuery()->chunkById($this->chunkSize, function ($mediaChunk) use ($presets, &$processed): void {
            if ($this->batch()?->cancelled()) {
                return;
            }

            foreach ($mediaChunk as $media) {
                /** @var Media $media */
                if (! $media->type->supportsConversions()) {
                    continue;
                }

                foreach ($presets as $name => $preset) {
                    GenerateImageVariationJob::dispatch(
                        $media->id,
                        (string) $name,
                        $preset,
                        $media->revision,
                    )->afterCommit();
                }

                $processed++;
            }
        });

        Log::info('RegenerateMediaVariationsJob: dispatched variations.', [
            'processed_count' => $processed,
            'type' => $this->type?->value,
            'disk' => $this->disk,
            'created_after' => $this->createdAfter,
            'created_before' => $this->createdBefore,
            'preset_names' => $this->presetNames,
        ]);
    }

    /**
     * Build the filtered query for matching media records.
     *
     * @return Builder<Media>
     */
    private function buildQuery(): Builder
    {
        $query = Media::query()->available();

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
        Log::error('RegenerateMediaVariationsJob failed.', [
            'exception' => $exception?->getMessage(),
        ]);
    }
}
