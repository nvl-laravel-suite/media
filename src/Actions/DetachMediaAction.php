<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Events\MediaDetached;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Support\MediaAssociationSnapshot;

/**
 * Removes association records between a media and a model.
 */
final readonly class DetachMediaAction implements DetachMediaContract
{
    public function __construct(private MediaMutationLock $mutationLock) {}

    /**
     * Delete association record(s) for the given media and model, optionally scoped to a collection.
     *
     * @param  Media|string  $media  Media instance or UUID
     * @param  string|null  $collection  When provided, only detach from this collection
     * @return int Number of deleted pivot records
     */
    public function execute(
        Media|string $media,
        Model $model,
        ?string $collection = null,
    ): int {
        $mediaId = $media instanceof Media ? $media->id : $media;

        /** @var array{0: int, 1: array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string|null}>} $result */
        $result = $this->mutationLock->execute($mediaId, function () use ($mediaId, $model, $collection): array {
            return DB::transaction(function () use ($mediaId, $model, $collection): array {
                Media::query()
                    ->withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($mediaId);
                $query = MediaAssociation::where('media_id', $mediaId)
                    ->where('associable_type', $model->getMorphClass())
                    ->where('associable_id', $model->getKey());

                if ($collection !== null) {
                    $query->where('collection', $collection);
                }

                $associations = $query->get();
                $snapshots = MediaAssociationSnapshot::fromAssociations($associations);
                $count = $query->delete();

                return [$count, $snapshots];
            });
        });

        [$count, $snapshots] = $result;

        if ($count > 0) {
            MediaDetached::dispatch($mediaId, $snapshots);
        }

        return $count;
    }
}
