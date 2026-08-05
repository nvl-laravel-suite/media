<?php

declare(strict_types=1);

namespace Nvl\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaConfiguredVariationService;
use Nvl\Media\Support\MediaQueueConfiguration;
use Throwable;

/** ProcessMediaVariationsJob: dispatches all configured variation jobs for a freshly uploaded media record. */
final class ProcessMediaVariationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(
        private readonly string $mediaId,
        private readonly bool $includeOutputConversion = true,
    ) {
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
        $media = Media::find($this->mediaId);

        if ($media === null) {
            Log::warning('ProcessMediaVariationsJob: media not found, skipping.', [
                'media_id' => $this->mediaId,
            ]);

            return;
        }

        if (! $media->type->supportsConversions()) {
            return;
        }

        foreach ($configuredVariationService->configuredQueuePayloadsFor(
            media: $media,
            includeOutputConversion: $this->includeOutputConversion,
            includePresets: (bool) config('media.auto_generate_variations', true),
        ) as $name => $preset) {
            GenerateImageVariationJob::dispatch(
                $media->id,
                $name,
                $preset,
                $media->revision,
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
        return $this->mediaId;
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
        Log::error('ProcessMediaVariationsJob exhausted its retry budget.', [
            'media_id' => $this->mediaId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
