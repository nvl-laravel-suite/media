<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaQueryService;

/**
 * Adds tags to a set of media records in one transaction.
 */
final class BulkTagMediaAction
{
    public function __construct(
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaQueryService $queries,
    ) {}

    /**
     * Add tags to multiple media records atomically.
     *
     * @param  array<int, string>  $ids  Media UUIDs
     * @param  array<int, string>  $tags  Tags to add
     * @return int Number of tagged records
     */
    public function execute(array $ids, array $tags): int
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return 0;
        }
        $this->queries->findMany($ids);

        return $this->mutationLock->executeMany($ids, function () use ($ids, $tags): int {
            return DB::transaction(function () use ($ids, $tags): int {
                $mediaItems = Media::query()
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $count = 0;

                foreach ($mediaItems as $media) {
                    /** @var Media $media */
                    $existing = $media->tags ?? [];
                    $merged = array_values(array_unique(array_merge($existing, $tags)));
                    $media->update(['tags' => $merged]);
                    $count++;
                }

                return $count;
            });
        });
    }
}
