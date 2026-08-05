<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/** MediaLifecycleService: handles media collection clearing, deletion, and detachment in transactions. */
final class MediaLifecycleService
{
    public function __construct(
        private readonly DeleteMediaContract $deleteAction,
        private readonly DetachMediaContract $detachAction,
        private readonly MediaMutationLock $mutationLock,
    ) {}

    /**
     * Delete all media in a collection for a model.
     */
    public function clearCollection(Model&HasMedia $model, string $collection = 'default'): void
    {
        $this->removeManyFromModel($model, $model->getMedia($collection), $collection);
    }

    /**
     * Delete all media in a collection except specified IDs.
     *
     * @param  array<int, Media|string>|Collection<int, Media|string>  $except
     */
    public function clearCollectionExcept(
        Model&HasMedia $model,
        string $collection = 'default',
        array|Collection $except = [],
    ): void {
        if ($except instanceof Collection) {
            $exceptIds = $except->pluck('id')->all();
        } else {
            $exceptIds = collect($except)->map(fn ($item) => $item instanceof Media ? $item->id : $item)->all();
        }

        $items = $model->getMedia($collection)
            ->reject(fn (Media $media) => in_array($media->id, $exceptIds, true));

        $this->removeManyFromModel($model, $items, $collection);
    }

    /**
     * Delete a single media record and its files.
     */
    public function deleteMedia(string|Media $media): void
    {
        $this->deleteAction->execute($media);
    }

    /**
     * Delete all media associated with a model.
     */
    public function deleteAll(Model&HasMedia $model): void
    {
        $this->removeManyFromModel($model, $model->media()->get());
    }

    /**
     * Detach media from a model (remove pivot without deleting the file).
     */
    public function detach(string|Media $media, Model $model, ?string $collection = null): void
    {
        $this->detachAction->execute($media, $model, $collection);
    }

    /**
     * Remove a media association from one model, deleting the underlying row only when it is no longer shared.
     */
    public function removeFromModel(Model&HasMedia $model, string|Media $media, ?string $collection = null): void
    {
        $mediaId = $media instanceof Media ? $media->id : $media;

        $this->mutationLock->execute($mediaId, function () use ($model, $mediaId, $collection): void {
            DB::transaction(function () use ($model, $mediaId, $collection): void {
                $resolvedMedia = Media::query()->lockForUpdate()->findOrFail($mediaId);

                $this->removeResolvedFromModel($model, $resolvedMedia, $collection);
            });
        });
    }

    /**
     * Remove a collection of media items from one model inside a transaction.
     *
     * @param  Collection<int, Media>  $items
     */
    private function removeManyFromModel(Model&HasMedia $model, Collection $items, ?string $collection = null): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $mediaIds = array_values(
            $items
                ->pluck('id')
                ->filter(static fn (mixed $id): bool => is_string($id))
                ->all(),
        );

        $this->mutationLock->executeMany($mediaIds, function () use ($model, $items, $collection): void {
            DB::transaction(function () use ($model, $items, $collection): void {
                foreach ($items as $media) {
                    $resolvedCollection = $collection;

                    if ($resolvedCollection === null) {
                        $pivotCollection = data_get($media, 'pivot.collection');
                        $resolvedCollection = is_string($pivotCollection) ? $pivotCollection : null;
                    }

                    $resolvedMedia = Media::query()
                        ->lockForUpdate()
                        ->findOrFail($media->id);

                    $this->removeResolvedFromModel($model, $resolvedMedia, $resolvedCollection);
                }
            });
        });
    }

    /**
     * Remove a resolved media record from one model, detaching only the targeted association when shared elsewhere.
     */
    private function removeResolvedFromModel(Model&HasMedia $model, Media $media, ?string $collection = null): void
    {
        $targetAssociationIds = MediaAssociation::query()
            ->where('media_id', $media->id)
            ->where('associable_type', $model->getMorphClass())
            ->where('associable_id', $model->getKey())
            ->when($collection !== null, fn ($query) => $query->where('collection', $collection))
            ->pluck('id');

        if ($targetAssociationIds->isEmpty()) {
            return;
        }

        $hasOtherAssociations = MediaAssociation::query()
            ->where('media_id', $media->id)
            ->whereNotIn('id', $targetAssociationIds)
            ->exists();

        if ($hasOtherAssociations) {
            $this->detachAction->execute($media, $model, $collection);

            return;
        }

        $this->deleteAction->execute($media);
    }
}
