<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Support\MediaBatchOwnerAdmission;

/** MediaLifecycleService: handles media collection clearing, deletion, and detachment in transactions. */
final class MediaLifecycleService
{
    public function __construct(
        private readonly DeleteMediaContract $deleteAction,
        private readonly DetachMediaContract $detachAction,
        private readonly MediaMutationLock $mutationLock,
        private readonly AttachMediaContract $attachAction,
    ) {}

    /** Synchronize a collection atomically through the owned attachment boundaries.
     * @param  array<int, Media>|Collection<int, Media>  $media
     */
    public function sync(Model&HasNvlMedia $model, array|Collection $media, string $collection = 'default'): void
    {
        $items = $media instanceof Collection ? $media : new Collection($media);
        $identifiers = $items->map(static fn (Media $asset): string => $asset->id)->all();
        $this->mutationLock->executeForOwnerCollection($model, $collection, function () use ($model, $items, $identifiers, $collection): void {
            (new MediaAssociation)->getConnection()->transaction(function () use ($model, $items, $identifiers, $collection): void {
                foreach ($model->nvlMedia()->all($collection) as $asset) {
                    if (! in_array($asset->id, $identifiers, true)) {
                        $this->detachAction->execute($asset, $model, $collection);
                    }
                }
                foreach ($items as $order => $asset) {
                    $this->attachAction->execute($asset, $model, $collection, order: $order);
                }
            });
        });
    }

    /**
     * Delete all media in a collection for a model.
     */
    public function clearCollection(Model&HasNvlMedia $model, string $collection = 'default'): void
    {
        $this->removeManyFromModel($model, $model->nvlMedia()->all($collection), $collection);
    }

    /**
     * Delete all media in a collection except specified IDs.
     *
     * @param  array<int, Media|string>|Collection<int, Media|string>  $except
     */
    public function clearCollectionExcept(
        Model&HasNvlMedia $model,
        string $collection = 'default',
        array|Collection $except = [],
    ): void {
        if ($except instanceof Collection) {
            $exceptIds = $except->pluck('id')->all();
        } else {
            $exceptIds = collect($except)->map(fn ($item) => $item instanceof Media ? $item->id : $item)->all();
        }

        $items = $model->nvlMedia()->all($collection)
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
    public function deleteAll(Model&HasNvlMedia $model): void
    {
        $this->removeManyFromModel($model, $model->nvlMedia()->query()->get());
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
     *
     * @param  Closure(Media): void|null  $beforeOrphanDelete
     */
    public function removeFromModel(Model&HasNvlMedia $model, string|Media $media, ?string $collection = null, ?Closure $beforeOrphanDelete = null): void
    {
        $mediaId = $media instanceof Media ? $media->id : $media;

        $this->mutationLock->execute($mediaId, function () use ($model, $mediaId, $collection, $beforeOrphanDelete): void {
            MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($model, $mediaId, $collection, $beforeOrphanDelete): void {
                $resolvedMedia = MediaModelRegistry::query()->lockForUpdate()->findOrFail($mediaId);

                $this->removeResolvedFromModel($model, $resolvedMedia, $collection, $beforeOrphanDelete);
            });
        });
    }

    /**
     * Remove a collection of media items from one model inside a transaction.
     *
     * @param  Collection<int, Media>  $items
     */
    private function removeManyFromModel(Model&HasNvlMedia $model, Collection $items, ?string $collection = null): void
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
            MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($model, $items, $collection): void {
                foreach ($items as $media) {
                    $resolvedCollection = $collection;

                    if ($resolvedCollection === null) {
                        $pivotCollection = data_get($media, 'pivot.collection');
                        $resolvedCollection = is_string($pivotCollection) ? $pivotCollection : null;
                    }

                    $resolvedMedia = MediaModelRegistry::query()
                        ->lockForUpdate()
                        ->findOrFail($media->id);

                    $this->removeResolvedFromModel($model, $resolvedMedia, $resolvedCollection);
                }
            });
        });
    }

    /**
     * Remove targeted usages and bypass the public reuse guard only after the held asset lock proves every usage is selected.
     *
     * @param  Closure(Media): void|null  $beforeOrphanDelete
     */
    private function removeResolvedFromModel(Model&HasNvlMedia $model, Media $media, ?string $collection = null, ?Closure $beforeOrphanDelete = null): void
    {
        $targetAssociationIds = MediaAssociation::query()
            ->where('media_id', $media->id);
        $ownerKey = $model->getKey();
        if (! is_int($ownerKey) && ! is_string($ownerKey)) {
            throw new InvalidArgumentException('Media removal requires a scalar owner key.');
        }
        MediaBatchOwnerAdmission::exact($targetAssociationIds, 'associable_type', $model->getMorphClass());
        MediaBatchOwnerAdmission::exact($targetAssociationIds, 'associable_id', (string) $ownerKey);
        if ($collection !== null) {
            MediaBatchOwnerAdmission::exact($targetAssociationIds, 'collection', $collection);
        }
        $targetAssociationIds = $targetAssociationIds->pluck('id');

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

        $beforeOrphanDelete?->__invoke($media);
        $this->deleteAction->execute($media, force: true);
    }
}
