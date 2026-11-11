<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Events\MediaAttached;
use Nvl\Media\Events\MediaDetached;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaInUseException;
use Nvl\Media\Exceptions\MediaNotReusableException;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Ownership\MediaOwnership;
use Nvl\Media\Ownership\MediaOwnershipParentResolver;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaActors;
use Nvl\Media\Support\MediaAssociationSnapshot;
use Nvl\Media\Support\MediaBatchOwnerAdmission;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Support\MediaOwnerSlotMutationState;
use Nvl\Media\Support\MediaOwnerSlotOperationClaim;
use Nvl\Media\Support\MediaTemporaryLocalFile;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Locales\LocaleCode;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Throwable;

/**
 * Complete attachment, lifecycle and durable owner-slot operations.
 *
 * @api
 */
final class MediaAttachments
{
    public function __construct(
        private readonly MediaAccessService $access,
        private readonly MediaVariationDispatcher $variationDispatcher,
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaOwnershipParentResolver $owners,
        private readonly OwnershipBoundary $tenantBoundary,
        private readonly DomainEventDispatcher $domainEvents,
        private readonly OwnershipWriter $ownershipWriter,
        private readonly MediaBatchOwnerAdmission $removeOwners,
        private readonly MediaAuthorization $authorization,
        private readonly MediaMutationLock $lock,
        private readonly MediaLibrary $deleteAction,
        private readonly MediaOwnerSlotResolver $resolver,
        private readonly MediaStagingPolicy $stagingPolicy,
        private readonly MediaLibrary $deleteMedia,
        private readonly MediaOwnerSlotIdempotency $idempotency,
        private readonly MediaUploader $uploadMedia,
        private readonly MediaLocalFileMaterializer $materializer,
    ) {}

    /**
     * Attach a Media record to a model via a polymorphic pivot, deduplicating by composite key.
     *
     * After attaching, any model/collection-level conversions that don't already
     * exist as variations for this media will be generated.
     *
     * @param  array<string, mixed>  $metadata  Optional pivot metadata
     * @param  bool  $dispatchVariations  Whether to trigger association-driven variation generation
     * @param  bool  $requirePublic  Require public visibility inside the association transaction
     */
    /**
     * Attach an admitted asset under owner and asset locks, enforcing the selected slot.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function attach(
        Authenticatable|SystemActor $actor,
        Media $media,
        Model $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
        bool $requirePublic = false,
        ?MediaSlot $slot = null,
    ): MediaAssociation {
        $actor = MediaActors::assert($actor);
        $model = $this->owners->resolve($model);
        MediaOwnership::assertAsset($media);
        $this->access->authorize($actor, MediaAbility::Associate, $media, $model);
        if (trim($collection) === '' || mb_strlen($collection) > 50 || ($order !== null && $order < 0)) {
            throw new InvalidArgumentException('Media collections and ordering must be valid bounded values.');
        }
        if ($slot !== null && $slot->name !== $collection) {
            throw new InvalidArgumentException('The Media slot must match its collection.');
        }
        $locale = $locale === null ? null : (new LocaleCode($locale))->value;

        return $this->mutationLock->executeForOwnerCollection($model, $collection, function () use ($actor, $media, $model, $collection, $locale, $order, $metadata, $dispatchVariations, $requirePublic, $slot): MediaAssociation {
            return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($actor, $media, $model, $collection, $locale, $order, $metadata, $dispatchVariations, $requirePublic, $slot): MediaAssociation {
                $ownerId = $model->getKey();
                if (! is_int($ownerId) && ! is_string($ownerId)) {
                    throw new InvalidArgumentException('Media owners require a scalar key.');
                }
                $locked = MediaModelRegistry::query()->lockForUpdate()->findOrFail($media->id);
                if ($slot !== null) {
                    $this->stagingPolicy->assertFitsSlot($locked, $slot);
                    if ($slot->isExclusive()) {
                        $associations = $locked->associations()->lockForUpdate()->get();
                        foreach ($associations as $association) {
                            if ($association->associable_type !== $model->getMorphClass()
                                || (string) $association->associable_id !== (string) $ownerId
                                || $association->collection !== $collection) {
                                throw new FileUnacceptableForCollection('Exclusive Media cannot have a foreign owner or collection association.');
                            }
                        }
                    }
                }
                $association = $this->attachLocked($actor, $locked, $model, $collection, $locale, $order, $metadata, $dispatchVariations, $requirePublic);
                if ($slot?->slotSizeLimit !== null) {
                    $rows = MediaAssociation::query()->where('associable_type', $model->getMorphClass())
                        ->where('associable_id', (string) $ownerId)->where('collection', $collection)
                        ->whereKeyNot($association->id)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
                    $overflow = max(0, $rows->count() + 1 - $slot->slotSizeLimit);
                    foreach ($rows->take($overflow) as $old) {
                        $this->detach($actor, $old->media_id, $model, $collection);
                        if ($slot->isExclusive() && ! MediaAssociation::query()->where('media_id', $old->media_id)->exists()) {
                            $this->deleteMedia->delete($actor, $old->media_id, owner: $model);
                        }
                    }
                }

                return $association;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function attachLocked(Authenticatable|SystemActor $actor,
        Media $media,
        Model $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
        bool $requirePublic = false,
    ): MediaAssociation {
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Associate, MediaModelRegistry::query()->withTrashed()->findOrFail($media->id), $model);
        $this->tenantBoundary->assertRecord($media, 'media.assets');
        $model = $this->owners->resolve($model);
        $morph_type = $model->getMorphClass();
        $morph_id = $model->getKey();

        if ($morph_id === null) {
            throw new InvalidArgumentException('Cannot attach media to an unsaved model (model key is null).');
        }

        $association = $this->mutationLock->execute($media->id, function () use (&$media, $model, $morph_type, $morph_id, $collection, $locale, $order, $metadata, $requirePublic): MediaAssociation {
            return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use (&$media, $model, $morph_type, $morph_id, $collection, $locale, $order, $metadata, $requirePublic): MediaAssociation {
                $media = MediaModelRegistry::query()->lockForUpdate()->findOrFail($media->id);
                $model = $this->owners->resolve($model, true);
                $morph_type = $model->getMorphClass();
                $morph_id = $model->getKey();

                if ($requirePublic && ! $media->is_public) {
                    throw MediaNotReusableException::privateAsset($media->id);
                }

                if (! $media->isAvailable()) {
                    throw new MediaUploadException(
                        "Media [{$media->id}] cannot be associated while its status is [{$media->status->value}].",
                    );
                }

                $association = MediaAssociation::query()->firstOrNew([
                    'media_id' => $media->id,
                    'associable_type' => $morph_type,
                    'associable_id' => $morph_id,
                    'collection' => $collection,
                ]);
                $association->fill([
                    'locale' => $locale,
                    'order' => $order ?? 0,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                ]);
                if (! $association->exists) {
                    $association->forceFill(['is_active' => true, 'replaced_at' => null]);
                }
                $association->forceFill($this->tenantBoundary->attributes('media.associations'));
                if ($association->exists) {
                    $association->save();
                } else {
                    $this->ownershipWriter->create(MediaAssociation::class, static function (array $attributes) use ($association): MediaAssociation {
                        $association->forceFill($attributes)->save();

                        return $association;
                    });
                }

                $this->domainEvents->dispatch(MediaAttached::fromAssociation($association), $association->getConnection());

                return $association;
            });
        });

        // Generate any model/collection conversions that don't already exist for this media.
        if ($dispatchVariations && $model instanceof HasNvlMedia) {
            $slotName = data_get($metadata, 'slot');
            $resolvedSlotName = is_string($slotName) && $slotName !== ''
                ? $slotName
                : $collection;

            $this->variationDispatcher->dispatchMissingForAssociation($media, $model, $resolvedSlotName);
        }

        return $association;
    }

    /**
     * Delete association record(s) for the given media and model, optionally scoped to a collection.
     *
     * @param  Media|string  $media  Media instance or UUID
     * @param  string|null  $collection  When provided, only detach from this collection
     * @return int Number of deleted pivot records
     */
    public function detach(Authenticatable|SystemActor $actor,
        Media|string $media,
        Model $model,
        ?string $collection = null,
    ): int {
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Associate, MediaModelRegistry::query()->withTrashed()->findOrFail($media instanceof Media ? $media->id : $media), $model);
        $model = $this->owners->resolve($model);
        $mediaId = $media instanceof Media ? $media->id : $media;

        /** @var array{0: int, 1: array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string|null}>} $result */
        $result = $this->mutationLock->execute($mediaId, function () use ($mediaId, $model, $collection): array {
            return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($mediaId, $model, $collection): array {
                MediaModelRegistry::query()
                    ->withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($mediaId);
                $query = MediaAssociation::where('media_id', $mediaId);
                $ownerKey = $model->getKey();
                if (! is_int($ownerKey) && ! is_string($ownerKey)) {
                    throw new InvalidArgumentException('Media removal requires a scalar owner key.');
                }
                MediaBatchOwnerAdmission::exact($query, 'associable_type', $model->getMorphClass());
                MediaBatchOwnerAdmission::exact($query, 'associable_id', (string) $ownerKey);

                if ($collection !== null) {
                    MediaBatchOwnerAdmission::exact($query, 'collection', $collection);
                }

                $associations = $query->get();
                $snapshots = MediaAssociationSnapshot::fromAssociations($associations);
                $count = $query->delete();

                if ($count > 0) {
                    $this->domainEvents->dispatch(new MediaDetached($mediaId, $snapshots), (MediaModelRegistry::newInstance())->getConnection());
                }

                return [$count, $snapshots];
            });
        });

        [$count, $snapshots] = $result;

        return $count;
    }

    /**
     * Create the serialized public media reuse action.
     */

    /**
     * Attach an asset only while its locked, persisted state permits public reuse.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function reuse(Authenticatable|SystemActor $actor,
        Media|string $media,
        Model&HasNvlMedia $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): MediaAssociation {
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Associate, MediaModelRegistry::query()->withTrashed()->findOrFail($media instanceof Media ? $media->id : $media), $model);
        $resolvedMedia = is_string($media)
            ? MediaModelRegistry::query()->findOrFail($media)
            : $media;

        return $this->attach($actor,
            media: $resolvedMedia,
            model: $model,
            collection: $collection,
            locale: $locale,
            order: $order,
            metadata: $metadata,
            dispatchVariations: $dispatchVariations,
            requirePublic: true,
        );
    }

    /** Retain canonical owner admission, authorization and native lifecycle collaborators. */

    /** Remove matching owner usages and authorize deletion only when native lifecycle selects an orphan. */
    public function remove(Authenticatable|SystemActor $actor, Model&HasNvlMedia $owner, string $mediaId, ?string $collection = null): void
    {
        $actor = MediaActors::assert($actor);
        if (! Str::isUuid($mediaId)) {
            throw new InvalidArgumentException('Owner Media removal requires a valid Media UUID.');
        }
        if ($collection !== null && (trim($collection) === '' || mb_strlen($collection) > 50)) {
            throw new InvalidArgumentException('Media collection names must be nonempty and at most fifty characters.');
        }
        $this->lock->execute($mediaId, function () use ($actor, $owner, $mediaId, $collection): void {
            MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($actor, $owner, $mediaId, $collection): void {
                $batch = $this->removeOwners->admit(OwnerBatch::fromModels([$owner]));
                $canonical = $batch->owners()[0];
                if (! $canonical instanceof HasNvlMedia) {
                    throw new InvalidArgumentException('A canonical Media-capable owner is required.');
                }
                $media = MediaModelRegistry::query()->lockForUpdate()->findOrFail($mediaId);
                $selected = MediaAssociation::query()->where('media_id', $media->id);
                MediaBatchOwnerAdmission::exact($selected, 'associable_type', $canonical->getMorphClass());
                MediaBatchOwnerAdmission::exact($selected, 'associable_id', $batch->identities()[0]->id);
                if ($collection !== null) {
                    MediaBatchOwnerAdmission::exact($selected, 'collection', $collection);
                }
                $selected->firstOrFail();
                $this->attachmentsRemoveAuthorize($actor, MediaAbility::Associate, $media, $canonical);
                $this->removeFromModel($actor, $canonical, $media, $collection, function (Media $orphan) use ($actor, $canonical): void {
                    $this->attachmentsRemoveAuthorize($actor, MediaAbility::Delete, $orphan, $canonical);
                });
            }, 3);
        });
    }

    /** Require the host's current capability decision for the canonical owner and asset. */
    private function attachmentsRemoveAuthorize(Authenticatable|SystemActor $actor, MediaAbility $ability, Media $media, Model $owner): void
    {
        if (! $this->authorization->allows($actor, $ability, $media, $owner)) {
            throw new AuthorizationException('The selected Media owner removal is not authorized.');
        }
    }

    /** Synchronize a collection atomically through the owned attachment boundaries.
     * @param  array<int, Media>|Collection<int, Media>  $media
     */
    public function sync(Authenticatable|SystemActor $actor, Model&HasNvlMedia $model, array|Collection $media, string $collection = 'default'): void
    {
        $actor = MediaActors::assert($actor);
        $items = $media instanceof Collection ? $media : new Collection($media);
        $identifiers = $items->map(static fn (Media $asset): string => $asset->id)->all();
        $this->mutationLock->executeForOwnerCollection($model, $collection, function () use ($actor, $model, $items, $identifiers, $collection): void {
            (new MediaAssociation)->getConnection()->transaction(function () use ($actor, $model, $items, $identifiers, $collection): void {
                foreach ($this->ownerAssets($actor, $model, $collection) as $asset) {
                    if (! in_array($asset->id, $identifiers, true)) {
                        $this->detach($actor, $asset, $model, $collection);
                    }
                }
                foreach ($items as $order => $asset) {
                    $this->attach($actor, $asset, $model, $collection, order: $order);
                }
            });
        });
    }

    /**
     * Delete all media in a collection for a model.
     */
    public function clearCollection(Authenticatable|SystemActor $actor, Model&HasNvlMedia $model, string $collection = 'default'): void
    {
        $actor = MediaActors::assert($actor);
        $this->lifecycleserviceRemoveManyFromModel($actor, $model, $this->ownerAssets($actor, $model, $collection), $collection);
    }

    /**
     * Delete all media in a collection except specified IDs.
     *
     * @param  array<int, Media|string>|Collection<int, Media|string>  $except
     */
    public function clearCollectionExcept(Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $model,
        string $collection = 'default',
        array|Collection $except = [],
    ): void {
        $actor = MediaActors::assert($actor);
        if ($except instanceof Collection) {
            $exceptIds = $except->pluck('id')->all();
        } else {
            $exceptIds = collect($except)->map(fn ($item) => $item instanceof Media ? $item->id : $item)->all();
        }

        $items = $this->ownerAssets($actor, $model, $collection)
            ->reject(fn (Media $media) => in_array($media->id, $exceptIds, true));

        $this->lifecycleserviceRemoveManyFromModel($actor, $model, $items, $collection);
    }

    /**
     * Delete all media associated with a model.
     */
    public function deleteAll(Authenticatable|SystemActor $actor, Model&HasNvlMedia $model): void
    {
        $actor = MediaActors::assert($actor);
        $this->lifecycleserviceRemoveManyFromModel($actor, $model, $this->ownerAssets($actor, $model));
    }

    /**
     * Remove a media association from one model, deleting the underlying row only when it is no longer shared.
     *
     * @param  Closure(Media): void|null  $beforeOrphanDelete
     */
    public function removeFromModel(Authenticatable|SystemActor $actor, Model&HasNvlMedia $model, string|Media $media, ?string $collection = null, ?Closure $beforeOrphanDelete = null): void
    {
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Associate, MediaModelRegistry::query()->withTrashed()->findOrFail($media instanceof Media ? $media->id : $media), $model);
        $mediaId = $media instanceof Media ? $media->id : $media;

        $this->mutationLock->execute($mediaId, function () use ($actor, $model, $mediaId, $collection, $beforeOrphanDelete): void {
            MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($actor, $model, $mediaId, $collection, $beforeOrphanDelete): void {
                $resolvedMedia = MediaModelRegistry::query()->lockForUpdate()->findOrFail($mediaId);

                $this->lifecycleserviceRemoveResolvedFromModel($actor, $model, $resolvedMedia, $collection, $beforeOrphanDelete);
            });
        });
    }

    /**
     * Remove a collection of media items from one model inside a transaction.
     *
     * @param  Collection<int, Media>  $items
     */
    private function lifecycleserviceRemoveManyFromModel(Authenticatable|SystemActor $actor, Model&HasNvlMedia $model, Collection $items, ?string $collection = null): void
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

        $this->mutationLock->executeMany($mediaIds, function () use ($actor, $model, $items, $collection): void {
            MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($actor, $model, $items, $collection): void {
                foreach ($items as $media) {
                    $resolvedMedia = MediaModelRegistry::query()
                        ->lockForUpdate()
                        ->findOrFail($media->id);

                    // Removing every usage names each owner placement, so one asset in two collections is not "reused".
                    $placements = $collection !== null
                        ? [$collection]
                        : $this->ownerPlacements($model, $resolvedMedia)->get()
                            ->map(static fn (MediaAssociation $placement): string => $placement->collection)->all();

                    foreach ($placements as $placement) {
                        $this->lifecycleserviceRemoveResolvedFromModel($actor, $model, $resolvedMedia, $placement);
                    }
                }
            });
        });
    }

    /**
     * Remove targeted usages while retaining the public reuse guard under the held asset lock.
     *
     * @param  Closure(Media): void|null  $beforeOrphanDelete
     */
    private function lifecycleserviceRemoveResolvedFromModel(Authenticatable|SystemActor $actor, Model&HasNvlMedia $model, Media $media, ?string $collection = null, ?Closure $beforeOrphanDelete = null): void
    {
        $targetAssociationIds = $this->ownerPlacements($model, $media, $collection)->pluck('id');

        if ($targetAssociationIds->isEmpty()) {
            return;
        }

        $hasOtherAssociations = MediaAssociation::query()
            ->where('media_id', $media->id)
            ->whereNotIn('id', $targetAssociationIds)
            ->exists();

        if ($hasOtherAssociations) {
            $this->detach($actor, $media, $model, $collection);

            return;
        }

        if ($media->is_public && (bool) config('nvl-media.prevent_deleting_reused_public_media', true)) {
            $activeCount = MediaAssociation::query()
                ->where('media_id', $media->id)
                ->where('is_active', true)
                ->count();
            if ($activeCount > 1) {
                throw MediaInUseException::publicAsset($media->id, $activeCount);
            }
        }

        $beforeOrphanDelete?->__invoke($media);
        $this->deleteAction->delete($actor, $media, force: true, owner: $model);
    }

    /**
     * Select one owner's usages of an asset, optionally limited to a collection.
     *
     * @return Builder<MediaAssociation>
     */
    private function ownerPlacements(Model $model, Media $media, ?string $collection = null): Builder
    {
        $ownerKey = $model->getKey();
        if (! is_int($ownerKey) && ! is_string($ownerKey)) {
            throw new InvalidArgumentException('Media removal requires a scalar owner key.');
        }
        $placements = MediaAssociation::query()->where('media_id', $media->id);
        MediaBatchOwnerAdmission::exact($placements, 'associable_type', $model->getMorphClass());
        MediaBatchOwnerAdmission::exact($placements, 'associable_id', (string) $ownerKey);
        if ($collection !== null) {
            MediaBatchOwnerAdmission::exact($placements, 'collection', $collection);
        }

        return $placements;
    }

    /**
     * Return the authorized model for a registered owner slot.
     */
    public function getSlot(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
    ): ?Media {
        $actor = MediaActors::assert($actor);
        $slot = $this->resolver->slot($owner, $slot)->name;
        $association = $this->resolver->currentAssociation($owner, $slot);
        $media = $association instanceof MediaAssociation
            ? $this->ownerslotcopyworkflowMedia($actor, $association->media_id)
            : null;

        // A slot whose Media was deleted or quarantined after attachment reads as empty, under the same
        // check as an empty slot; it can still be cleared or replaced.
        if ($media instanceof Media && ($media->trashed() || ! $media->isAvailable())) {
            $media = null;
        }

        $this->ownerslotworkflowAuthorize($actor, MediaAbility::View, $media, $owner);

        if (! $association instanceof MediaAssociation || ! $media instanceof Media) {
            return null;
        }

        return $this->ownerslotworkflowProject($actor, $media, $association);
    }

    /**
     * Replace a registered owner slot with an existing authorized Media record.
     */
    public function replaceSlot(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        string $mediaId,
        ?string $idempotencyKey = null,
    ): Media {
        $actor = MediaActors::assert($actor);
        $slotDefinition = $this->resolver->slot($owner, $slot);
        $slot = $slotDefinition->name;
        $mediaId = $this->ownerslotworkflowMediaId($actor, $mediaId, 'replacements');
        $claim = null;

        try {
            if ($idempotencyKey !== null) {
                $claim = $this->idempotency->begin(
                    key: $idempotencyKey,
                    actor: $actor,
                    owner: $owner,
                    slot: $slot,
                    operation: MediaOwnerSlotOperationType::Replace,
                    payload: ['media_id' => $mediaId],
                );
            }

            if ($claim?->replayed === true) {
                $replayMedia = MediaModelRegistry::query()->withTrashed()->find($mediaId);
                $this->ownerslotworkflowAuthorize(
                    $actor,
                    MediaAbility::Associate,
                    $replayMedia instanceof Media ? $replayMedia : null,
                    $owner,
                );

                return $this->ownerslotworkflowReplayProjection($actor, $owner, $slot, $claim);
            }

            $candidate = $this->ownerslotworkflowAvailableMedia($actor, $mediaId);
            $this->ownerslotworkflowAuthorize(
                $actor,
                MediaAbility::Associate,
                $candidate,
                $owner,
            );

            $result = $this->mutationLock->executeForOwnerCollection(
                $owner,
                $slot,
                function () use (
                    $actor,
                    $candidate,
                    $owner,
                    $slot,
                    $slotDefinition,
                ): Media {
                    $current = $this->resolver->currentAssociation($owner, $slot);
                    $mediaIds = [$candidate->id];

                    if ($current instanceof MediaAssociation) {
                        $mediaIds[] = $current->media_id;
                    }

                    return $this->mutationLock->executeMany(
                        $mediaIds,
                        fn (): Media => MediaModelRegistry::newInstance()->getConnection()->transaction(
                            fn (): Media => $this->ownerslotworkflowReplaceInTransaction(
                                actor: $actor,
                                owner: $owner,
                                slot: $slot,
                                slotDefinition: $slotDefinition,
                                candidateId: $candidate->id,
                            ),
                            3,
                        ),
                    );
                },
            );

            if ($claim instanceof MediaOwnerSlotOperationClaim) {
                $this->idempotency->complete(
                    $claim,
                    $result->id,
                    $this->slotSnapshot($result),
                );
            }

            return $result;
        } catch (Throwable $exception) {
            $this->ownerslotworkflowRecordFailure($actor, $claim, 'replace_failed');

            throw $exception;
        }
    }

    /**
     * Clear one registered owner slot without exposing lifecycle persistence.
     */
    public function clearSlot(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        ?string $idempotencyKey = null,
    ): void {
        $actor = MediaActors::assert($actor);
        $slotDefinition = $this->resolver->slot($owner, $slot);
        $slot = $slotDefinition->name;
        $durableClaim = null;

        if ($idempotencyKey !== null) {
            $durableClaim = $this->idempotency->completed(
                key: $idempotencyKey,
                actor: $actor,
                owner: $owner,
                slot: $slot,
                operation: MediaOwnerSlotOperationType::Clear,
                payload: [],
            );

            $durableClaim ??= $this->idempotency->checkpointed(
                key: $idempotencyKey,
                actor: $actor,
                owner: $owner,
                slot: $slot,
                operation: MediaOwnerSlotOperationType::Clear,
                payload: [],
            );

            if ($durableClaim instanceof MediaOwnerSlotOperationClaim) {
                $this->ownerslotworkflowAuthorizeClearReplay($actor, $owner, $durableClaim);
            }

            if ($durableClaim?->replayed === true) {
                return;
            }
        }

        if (! $durableClaim instanceof MediaOwnerSlotOperationClaim) {
            $currentAssociation = $this->resolver->currentAssociation($owner, $slot);
            $current = $currentAssociation instanceof MediaAssociation
                ? $this->ownerslotcopyworkflowMedia($actor, $currentAssociation->media_id)
                : null;
            $this->ownerslotworkflowAuthorize($actor, MediaAbility::Associate, $current, $owner);
        }

        $claim = null;
        $mutationState = new MediaOwnerSlotMutationState;

        try {
            if ($idempotencyKey !== null) {
                $claim = $this->idempotency->begin(
                    key: $idempotencyKey,
                    actor: $actor,
                    owner: $owner,
                    slot: $slot,
                    operation: MediaOwnerSlotOperationType::Clear,
                    payload: [],
                );
            }

            if ($claim?->replayed === true) {
                $this->ownerslotworkflowAuthorizeClearReplay($actor, $owner, $claim);

                return;
            }

            if ($claim instanceof MediaOwnerSlotOperationClaim
                && $claim->resultPayload !== null) {
                $this->ownerslotworkflowAuthorizeClearReplay($actor, $owner, $claim);

                if ($this->ownerslotworkflowClearCheckpointCommitted($actor, $owner, $slot, $claim)) {
                    $mutationState->markCommitted();
                    $this->idempotency->complete(
                        $claim,
                        null,
                        $claim->resultPayload,
                    );

                    return;
                }
            }

            $completeInsideMutation = $claim instanceof MediaOwnerSlotOperationClaim
                && $this->ownerslotworkflowLedgerSharesMediaConnection($actor);
            $resultPayload = $this->mutationLock->executeForOwnerCollection(
                $owner,
                $slot,
                function () use (
                    $actor,
                    $claim,
                    $completeInsideMutation,
                    $mutationState,
                    $owner,
                    $slot,
                    $slotDefinition,
                ): array {
                    $currentForLock = $this->resolver->currentAssociation($owner, $slot);
                    $mediaIds = $currentForLock instanceof MediaAssociation
                        ? [$currentForLock->media_id]
                        : [];

                    return $this->mutationLock->executeMany(
                        $mediaIds,
                        function () use (
                            $actor,
                            $claim,
                            $completeInsideMutation,
                            $mutationState,
                            $owner,
                            $slot,
                            $slotDefinition,
                        ): array {
                            return MediaModelRegistry::newInstance()->getConnection()->transaction(
                                function () use (
                                    $actor,
                                    $claim,
                                    $completeInsideMutation,
                                    $mutationState,
                                    $owner,
                                    $slot,
                                    $slotDefinition,
                                ): array {
                                    MediaModelRegistry::newInstance()->getConnection()->afterCommit(
                                        $mutationState->markCommitted(...),
                                    );

                                    if ($claim instanceof MediaOwnerSlotOperationClaim) {
                                        $this->idempotency->renew($claim);
                                    }

                                    $resultPayload = $claim instanceof MediaOwnerSlotOperationClaim
                                        && $claim->resultPayload !== null
                                        && $this->ownerslotworkflowClearCheckpointCommitted($actor,
                                            $owner,
                                            $slot,
                                            $claim,
                                            lockForUpdate: true,
                                        )
                                            ? $this->ownerslotworkflowClearCheckpointPayload($actor, $claim)
                                            : $this->ownerslotworkflowClearInTransaction(
                                                actor: $actor,
                                                owner: $owner,
                                                slot: $slot,
                                                slotDefinition: $slotDefinition,
                                            );

                                    if ($claim instanceof MediaOwnerSlotOperationClaim) {
                                        if ($completeInsideMutation) {
                                            $this->idempotency->complete(
                                                $claim,
                                                null,
                                                $resultPayload,
                                            );
                                        } else {
                                            $this->idempotency->checkpoint(
                                                $claim,
                                                null,
                                                $resultPayload,
                                            );
                                        }
                                    }

                                    return $resultPayload;
                                },
                                3,
                            );
                        },
                    );
                },
            );

            if ($claim instanceof MediaOwnerSlotOperationClaim
                && ! $completeInsideMutation) {
                $this->ownerslotworkflowCompleteSplitClearClaim($actor,
                    $claim,
                    $resultPayload,
                    $mutationState,
                );
            }
        } catch (Throwable $exception) {
            if (! $mutationState->committed()) {
                $this->ownerslotworkflowRecordFailure($actor, $claim, 'clear_failed');
            }

            throw $exception;
        }
    }

    /**
     * Execute the durable association transition under owner and Media locks.
     */
    private function ownerslotworkflowReplaceInTransaction(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        MediaSlot $slotDefinition,
        string $candidateId,
    ): Media {
        $this->ownerslotworkflowLockOwner($actor, $owner);
        $candidate = $this->ownerslotworkflowLockedMedia($actor, $candidateId);
        $currentAssociation = $this->resolver->currentAssociation(
            $owner,
            $slot,
            lockForUpdate: true,
        );
        $candidateAssociations = MediaAssociation::query()
            ->where('media_id', $candidate->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->stagingPolicy->assertFitsSlot($candidate, $slotDefinition);

        $this->ownerslotworkflowAuthorize(
            $actor,
            MediaAbility::Associate,
            $candidate,
            $owner,
        );

        if ($currentAssociation instanceof MediaAssociation
            && hash_equals($currentAssociation->media_id, $candidate->id)) {
            return $this->ownerslotworkflowProject($actor, $candidate, $currentAssociation);
        }

        $stagingAssociations = $this->stagingPolicy->removableAssociations(
            actor: $actor,
            owner: $owner,
            slot: $slotDefinition,
            media: $candidate,
            associations: $candidateAssociations,
        );
        $previous = $currentAssociation instanceof MediaAssociation
            ? $this->ownerslotworkflowLockedMedia($actor, $currentAssociation->media_id)
            : null;

        $resultAssociation = $this->attach($actor,
            media: $candidate,
            model: $owner,
            collection: $slot,
            metadata: ['slot' => $slot],
        );
        $this->ownerslotworkflowDetachStagingAssociations($actor, $candidate, $stagingAssociations);

        if ($previous instanceof Media) {
            $this->detach($actor, $previous, $owner, $slot);

            if ($slotDefinition->isExclusive()
                && ! $previous->trashed()
                && ! MediaAssociation::query()
                    ->where('media_id', $previous->id)
                    ->exists()) {
                $this->deleteMedia->delete($actor, $previous, owner: $owner);
            }
        }

        return $this->ownerslotworkflowProject($actor, $candidate, $resultAssociation);
    }

    /**
     * Clear one slot under durable owner, association, and Media locks.
     *
     * @return array{authorization_association_id: string|null, authorization_media_id: string|null}
     */
    private function ownerslotworkflowClearInTransaction(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        MediaSlot $slotDefinition,
    ): array {
        $this->ownerslotworkflowLockOwner($actor, $owner);
        $association = $this->resolver->currentAssociation(
            $owner,
            $slot,
            lockForUpdate: true,
        );
        $media = $association instanceof MediaAssociation
            ? $this->ownerslotworkflowLockedMedia($actor, $association->media_id)
            : null;

        $this->ownerslotworkflowAuthorize($actor, MediaAbility::Associate, $media, $owner);

        if (! $association instanceof MediaAssociation || ! $media instanceof Media) {
            return [
                'authorization_association_id' => null,
                'authorization_media_id' => null,
            ];
        }

        $resultPayload = [
            'authorization_association_id' => $association->id,
            'authorization_media_id' => $media->id,
        ];
        $this->detach($actor, $media, $owner, $slot);

        if ($slotDefinition->isExclusive()
            && ! $media->trashed()
            && ! MediaAssociation::query()
                ->where('media_id', $media->id)
                ->exists()) {
            $this->deleteMedia->delete($actor, $media, owner: $owner);
        }

        return $resultPayload;
    }

    /**
     * Remove only the exact association identities approved by the staging policy.
     *
     * @param  Collection<int, MediaAssociation>  $associations
     */
    private function ownerslotworkflowDetachStagingAssociations(Authenticatable|SystemActor $actor,
        Media $media,
        Collection $associations,
    ): void {
        foreach ($associations as $association) {
            $stagingOwner = $association->associable()->first();

            if (! $stagingOwner instanceof Model) {
                throw new LogicException(
                    "Media staging association [{$association->id}] has no resolvable owner.",
                );
            }

            $this->detach($actor,
                $media,
                $stagingOwner,
                $association->collection,
            );
        }
    }

    /**
     * Resolve a non-deleted, usable Media record.
     */
    private function ownerslotworkflowAvailableMedia(Authenticatable|SystemActor $actor, string $mediaId): Media
    {
        $media = MediaModelRegistry::query()->withTrashed()->find($mediaId);

        if (! $media instanceof Media) {
            throw new MediaUploadException(
                "Media [{$mediaId}] does not exist.",
            );
        }

        $this->ownerslotworkflowAssertAvailable($actor, $media);

        return $media;
    }

    /**
     * Reject a tombstoned or unusable Media record.
     */
    private function ownerslotworkflowAssertAvailable(Authenticatable|SystemActor $actor, Media $media): void
    {
        if ($media->trashed()) {
            throw new MediaUploadException(
                "Media [{$media->id}] is deleted and cannot be associated.",
            );
        }

        if (! $media->isAvailable()) {
            throw new MediaUploadException(
                "Media [{$media->id}] is not available for association.",
            );
        }
    }

    /**
     * Normalize one package-owned Media UUID before hashing or querying it.
     */
    private function ownerslotworkflowMediaId(Authenticatable|SystemActor $actor, string $mediaId, string $operation): string
    {
        $mediaId = Str::lower(trim($mediaId));

        if (! Str::isUuid($mediaId)) {
            throw new InvalidArgumentException(
                "Media owner-slot {$operation} require a valid Media UUID.",
            );
        }

        return $mediaId;
    }

    /**
     * Resolve and lock a Media row, including tombstones for explicit rejection.
     */
    private function ownerslotworkflowLockedMedia(Authenticatable|SystemActor $actor, string $mediaId): Media
    {
        $media = MediaModelRegistry::query()->withTrashed()
            ->whereKey($mediaId)
            ->lockForUpdate()
            ->first();

        if (! $media instanceof Media) {
            throw new MediaUploadException(
                "Media [{$mediaId}] does not exist.",
            );
        }

        return $media;
    }

    /**
     * Serialize even an initially empty slot through its durable owner row.
     *
     * Cross-connection owners continue to rely on the configured shared mutation lock.
     */
    private function ownerslotworkflowLockOwner(Authenticatable|SystemActor $actor, Model&HasNvlMedia $owner): void
    {
        $ownerConnection = $owner->getConnection();
        $mediaConnection = (new MediaAssociation)->getConnection();

        if ($ownerConnection->getName() !== $mediaConnection->getName()) {
            return;
        }

        $locked = $owner->newQuery()
            ->whereKey($owner->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof Model) {
            throw new MediaUploadException(
                'The persisted Media owner disappeared during its slot transition.',
            );
        }
    }

    /**
     * Determine whether ledger completion participates in the Media transaction.
     */
    private function ownerslotworkflowLedgerSharesMediaConnection(Authenticatable|SystemActor $actor): bool
    {
        return (new MediaOwnerSlotOperation)->getConnection()->getName()
            === (MediaModelRegistry::newInstance())->getConnection()->getName();
    }

    /**
     * Load a result projection from the current exact owner association.
     */
    private function ownerslotworkflowCurrentProjection(Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        string $expectedMediaId,
    ): Media {
        $association = $this->resolver->currentAssociation($owner, $slot);

        if (! $association instanceof MediaAssociation
            || ! hash_equals($association->media_id, $expectedMediaId)) {
            throw new LogicException(
                'The Media owner-slot transition did not produce the expected association.',
            );
        }

        return $this->ownerslotworkflowProject($actor,
            $this->ownerslotworkflowAvailableMedia($actor, $expectedMediaId),
            $association,
        );
    }

    /**
     * Resolve a completed idempotent result without rerunning lifecycle effects.
     */
    private function ownerslotworkflowReplayProjection(Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        MediaOwnerSlotOperationClaim $claim,
    ): Media {
        if ($claim->resultPayload !== null) {
            return $this->restoreSlotResult($claim->resultPayload);
        }

        if ($claim->resultMediaId === null) {
            throw new LogicException(
                'A completed Media replacement claim has no result Media identifier.',
            );
        }

        return $this->ownerslotworkflowCurrentProjection($actor, $owner, $slot, $claim->resultMediaId);
    }

    /**
     * Reauthorize a clear replay against the exact Media subject cleared earlier.
     */
    private function ownerslotworkflowAuthorizeClearReplay(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        MediaOwnerSlotOperationClaim $claim,
    ): void {
        $mediaId = $this->ownerslotworkflowClearCheckpointPayload($actor, $claim)['authorization_media_id'];

        if ($mediaId === null) {
            $this->ownerslotworkflowAuthorize($actor, MediaAbility::Associate, null, $owner);

            return;
        }

        if (! Str::isUuid($mediaId)) {
            throw new LogicException(
                'A completed Media clear claim has an invalid authorization subject.',
            );
        }

        $media = MediaModelRegistry::query()->withTrashed()->find($mediaId);

        if (! $media instanceof Media) {
            throw new LogicException(
                'The Media clear authorization subject no longer exists.',
            );
        }

        $this->ownerslotworkflowAuthorize($actor, MediaAbility::Associate, $media, $owner);
    }

    /**
     * Determine whether a checkpointed clear already removed its exact association.
     */
    private function ownerslotworkflowClearCheckpointCommitted(Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        MediaOwnerSlotOperationClaim $claim,
        bool $lockForUpdate = false,
    ): bool {
        $associationId = $this->ownerslotworkflowClearCheckpointPayload($actor, $claim)[
            'authorization_association_id'
        ];

        if ($associationId === null) {
            return true;
        }

        $current = $this->resolver->currentAssociation(
            $owner,
            $slot,
            $lockForUpdate,
        );

        return ! $current instanceof MediaAssociation
            || ! hash_equals($current->id, $associationId);
    }

    /**
     * Validate and return the durable clear authorization and association proof.
     *
     * @return array{authorization_association_id: string|null, authorization_media_id: string|null}
     */
    private function ownerslotworkflowClearCheckpointPayload(Authenticatable|SystemActor $actor,
        MediaOwnerSlotOperationClaim $claim,
    ): array {
        $payload = $claim->resultPayload;

        if (! is_array($payload)
            || ! array_key_exists('authorization_association_id', $payload)
            || ! array_key_exists('authorization_media_id', $payload)) {
            throw new LogicException(
                'A Media clear claim has no durable authorization checkpoint.',
            );
        }

        $associationId = $payload['authorization_association_id'];
        $mediaId = $payload['authorization_media_id'];

        if (($associationId !== null
                && (! is_string($associationId) || ! Str::isUuid($associationId)))
            || ($mediaId !== null
                && (! is_string($mediaId) || ! Str::isUuid($mediaId)))
            || (($associationId === null) !== ($mediaId === null))) {
            throw new LogicException(
                'A Media clear claim has an invalid durable authorization checkpoint.',
            );
        }

        return [
            'authorization_association_id' => $associationId,
            'authorization_media_id' => $mediaId,
        ];
    }

    /**
     * Complete a split-ledger clear at the durable Media root transaction boundary.
     *
     * @param  array{authorization_association_id: string|null, authorization_media_id: string|null}  $resultPayload
     */
    private function ownerslotworkflowCompleteSplitClearClaim(Authenticatable|SystemActor $actor,
        MediaOwnerSlotOperationClaim $claim,
        array $resultPayload,
        MediaOwnerSlotMutationState $mutationState,
    ): void {
        if ($mutationState->committed()) {
            $this->idempotency->complete($claim, null, $resultPayload);

            return;
        }

        MediaModelRegistry::newInstance()->getConnection()->afterCommit(function () use ($claim, $resultPayload): void {
            try {
                $this->idempotency->complete($claim, null, $resultPayload);
            } catch (Throwable $claimFailure) {
                report($claimFailure);
            }
        });
        DB::afterRollBack(function () use ($actor, $claim): void {
            $this->ownerslotworkflowRecordFailure($actor, $claim, 'clear_failed');
        });
    }

    /**
     * Load bounded projection relations and preserve the selected slot association.
     */
    private function ownerslotworkflowProject(Authenticatable|SystemActor $actor,
        Media $media,
        MediaAssociation $association,
    ): Media {
        $media->loadMissing(['translations', 'imageVariations']);
        $media->loadCount('associations');

        return $media->setRelation('pivot', $association);
    }

    /**
     * Enforce the consumer-owned capability boundary.
     *
     * @throws AuthorizationException
     */
    private function ownerslotworkflowAuthorize(
        Authenticatable|SystemActor $actor,
        MediaAbility $ability,
        ?Media $media,
        Model&HasNvlMedia $owner,
    ): void {
        if ($this->authorization->allows($actor, $ability, $media, $owner)) {
            return;
        }

        throw new AuthorizationException(
            "The actor is not authorized to {$ability->value} this Media owner slot.",
        );
    }

    /**
     * Mark an active idempotency claim failed without masking the domain exception.
     */
    private function ownerslotworkflowRecordFailure(Authenticatable|SystemActor $actor,
        ?MediaOwnerSlotOperationClaim $claim,
        string $failureCode,
    ): void {
        if (! $claim instanceof MediaOwnerSlotOperationClaim || $claim->replayed) {
            return;
        }

        try {
            $this->idempotency->fail($claim, $failureCode);
        } catch (Throwable $claimFailure) {
            report($claimFailure);
        }
    }

    /** @var list<string> */
    private const array DEFAULT_METADATA_KEYS = [
        'alt',
        'alt_text',
        'attribution',
        'author',
        'caption',
        'copyright',
        'credit',
        'description',
        'license',
        'license_url',
        'photographer',
        'source',
        'source_url',
        'title',
    ];

    /**
     * Copy one authorized source through canonical destination ingestion.
     */
    public function copySlot(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        string $sourceMediaId,
        ?string $idempotencyKey = null,
    ): Media {
        $actor = MediaActors::assert($actor);
        $slotDefinition = $this->resolver->slot($owner, $slot);
        $slot = $slotDefinition->name;
        $sourceMediaId = $this->ownerslotworkflowMediaId($actor, $sourceMediaId, 'copies');
        $source = $this->ownerslotcopyworkflowMedia($actor, $sourceMediaId);
        $this->ownerslotworkflowAuthorize($actor, MediaAbility::View, $source, $owner);
        $this->ownerslotworkflowAuthorize($actor, MediaAbility::Associate, $source, $owner);
        $claim = null;
        $mutationState = new MediaOwnerSlotMutationState;

        try {
            if ($idempotencyKey !== null) {
                $claim = $this->idempotency->begin(
                    key: $idempotencyKey,
                    actor: $actor,
                    owner: $owner,
                    slot: $slot,
                    operation: MediaOwnerSlotOperationType::Copy,
                    payload: ['source_media_id' => $sourceMediaId],
                );
            }

            if ($claim?->replayed === true) {
                return $this->ownerslotcopyworkflowReplayProjection($actor, $owner, $slot, $claim);
            }

            if ($claim instanceof MediaOwnerSlotOperationClaim) {
                $recovered = $this->ownerslotcopyworkflowRecoverCheckpoint($actor, $owner, $slot, $claim);

                if ($recovered instanceof Media) {
                    $mutationState->markCommitted();
                    $this->idempotency->complete(
                        $claim,
                        $recovered->id,
                        $this->slotSnapshot($recovered),
                    );

                    return $recovered;
                }
            }

            $this->ownerslotworkflowAssertAvailable($actor, $source);
            $completeInsideMutation = $claim instanceof MediaOwnerSlotOperationClaim
                && $this->ownerslotworkflowLedgerSharesMediaConnection($actor);
            $result = $this->mutationLock->executeForOwnerCollection(
                $owner,
                $slot,
                function () use (
                    $actor,
                    $claim,
                    $completeInsideMutation,
                    $mutationState,
                    $owner,
                    $slot,
                    $slotDefinition,
                    $sourceMediaId,
                ): Media {
                    if ($claim instanceof MediaOwnerSlotOperationClaim) {
                        $recovered = MediaModelRegistry::newInstance()->getConnection()->transaction(function () use (
                            $actor,
                            $claim,
                            $mutationState,
                            $owner,
                            $slot,
                        ): ?Media {
                            $this->idempotency->renew($claim);
                            $recovered = $this->ownerslotcopyworkflowRecoverCheckpoint(
                                $actor,
                                $owner,
                                $slot,
                                $claim,
                                lockForUpdate: true,
                            );

                            if ($recovered instanceof Media) {
                                $mutationState->markCommitted();
                            }

                            return $recovered;
                        });

                        if ($recovered instanceof Media) {
                            return $recovered;
                        }
                    }

                    $current = $this->resolver->currentAssociation($owner, $slot);
                    $mediaIds = [$sourceMediaId];

                    if ($current instanceof MediaAssociation) {
                        $mediaIds[] = $current->media_id;
                    }

                    return $this->mutationLock->executeMany(
                        $mediaIds,
                        fn (): Media => $this->ownerslotcopyworkflowCopyUnderAcquiredLocks(
                            actor: $actor,
                            owner: $owner,
                            slot: $slot,
                            slotDefinition: $slotDefinition,
                            sourceMediaId: $sourceMediaId,
                            claim: $claim,
                            completeInsideMutation: $completeInsideMutation,
                            mutationState: $mutationState,
                        ),
                    );
                },
            );

            if ($claim instanceof MediaOwnerSlotOperationClaim
                && $completeInsideMutation
                && $mutationState->result() === null) {
                $this->idempotency->complete(
                    $claim,
                    $result->id,
                    $this->slotSnapshot($result),
                );
            }

            if ($claim instanceof MediaOwnerSlotOperationClaim
                && ! $completeInsideMutation) {
                $this->ownerslotcopyworkflowCompleteSplitCopyClaim($actor,
                    $claim,
                    $result,
                    $mutationState,
                );
            }

            return $result;
        } catch (Throwable $exception) {
            if (! $mutationState->committed()) {
                $this->ownerslotworkflowRecordFailure($actor, $claim, 'copy_failed');
            }

            throw $exception;
        }
    }

    /**
     * Materialize and verify the source before entering canonical ingestion.
     */
    private function ownerslotcopyworkflowCopyUnderAcquiredLocks(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        MediaSlot $slotDefinition,
        string $sourceMediaId,
        ?MediaOwnerSlotOperationClaim $claim,
        bool $completeInsideMutation,
        MediaOwnerSlotMutationState $mutationState,
    ): Media {
        $source = $this->ownerslotworkflowAvailableMedia($actor, $sourceMediaId);

        try {
            $lease = $this->materializer->lease($source->disk, $source->buildPath());
        } catch (Throwable $exception) {
            throw new MediaUploadException(
                "Media source object [{$source->id}] could not be materialized.",
                previous: $exception,
            );
        }

        try {
            return MediaModelRegistry::newInstance()->getConnection()->transaction(
                fn (): Media => $this->ownerslotcopyworkflowCopyInTransaction(
                    actor: $actor,
                    owner: $owner,
                    slot: $slot,
                    slotDefinition: $slotDefinition,
                    sourceMediaId: $sourceMediaId,
                    lease: $lease,
                    claim: $claim,
                    completeInsideMutation: $completeInsideMutation,
                    mutationState: $mutationState,
                ),
                3,
            );
        } finally {
            $lease->release();
        }
    }

    /**
     * Persist one canonical copy and transition its destination under row locks.
     */
    private function ownerslotcopyworkflowCopyInTransaction(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        MediaSlot $slotDefinition,
        string $sourceMediaId,
        MediaTemporaryLocalFile $lease,
        ?MediaOwnerSlotOperationClaim $claim,
        bool $completeInsideMutation,
        MediaOwnerSlotMutationState $mutationState,
    ): Media {
        MediaModelRegistry::newInstance()->getConnection()->afterCommit(function () use (
            $mutationState,
        ): void {
            $mutationState->markCommitted();
        });
        $this->ownerslotworkflowLockOwner($actor, $owner);
        $source = $this->ownerslotworkflowLockedMedia($actor, $sourceMediaId);
        $this->ownerslotworkflowAssertAvailable($actor, $source);
        $this->ownerslotworkflowAuthorize($actor, MediaAbility::View, $source, $owner);
        $this->ownerslotworkflowAuthorize($actor, MediaAbility::Associate, $source, $owner);
        $this->ownerslotcopyworkflowVerifyMaterializedSource($actor, $source, $lease->path());

        // A copy keeps the source bytes: the destination slot's original-image conversion applies to uploads only.
        $storageSlot = clone $slotDefinition;
        $storageSlot->convertFormat = $storageSlot->convertQuality = $storageSlot->convertMaxSize = null;
        $copy = $this->uploadMedia->upload($actor,
            file: new UploadedFile(
                $lease->path(),
                $source->filename,
                $source->mime_type,
                null,
                true,
            ),
            disk: $slotDefinition->resolveDisk(),
            model: $owner,
            slot: $storageSlot,
            fileName: $source->filename,
            isPublic: $slotDefinition->isPublic,
            tags: $this->ownerslotcopyworkflowCopyTags($actor, $source),
            metadata: $this->ownerslotcopyworkflowCopyMetadata($actor, $source),
            allowDuplicates: false,
            deduplicateExisting: false,
            skipAutoVariations: true,
        );
        $copy = $this->ownerslotworkflowLockedMedia($actor, $copy->id);
        $this->ownerslotcopyworkflowAssertCanonicalCopy($actor, $source, $copy, $slotDefinition);

        if ($claim instanceof MediaOwnerSlotOperationClaim) {
            $this->idempotency->checkpoint($claim, $copy->id);
        }

        $result = $this->ownerslotcopyworkflowReplaceCanonicalCopy(
            actor: $actor,
            owner: $owner,
            slot: $slot,
            slotDefinition: $slotDefinition,
            candidateId: $copy->id,
        );
        $mutationState->recordResult($result);

        if ($claim instanceof MediaOwnerSlotOperationClaim) {
            $this->idempotency->checkpoint(
                $claim,
                $result->id,
                $this->slotSnapshot($result),
            );

            if ($completeInsideMutation) {
                $this->idempotency->complete(
                    $claim,
                    $result->id,
                    $this->slotSnapshot($result),
                );
            }
        }

        return $result;
    }

    /**
     * Replace the destination using a new canonical, unassociated upload.
     */
    private function ownerslotcopyworkflowReplaceCanonicalCopy(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        MediaSlot $slotDefinition,
        string $candidateId,
    ): Media {
        $candidate = $this->ownerslotworkflowLockedMedia($actor, $candidateId);
        $currentAssociation = $this->resolver->currentAssociation(
            $owner,
            $slot,
            lockForUpdate: true,
        );
        $candidateAssociations = MediaAssociation::query()
            ->where('media_id', $candidate->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->stagingPolicy->assertFitsSlot(
            $candidate,
            $slotDefinition,
            customAcceptorSatisfied: true,
        );

        if ($candidateAssociations->isNotEmpty()) {
            throw new MediaUploadException(
                'Canonical owner-slot copies must produce an unassociated Media record.',
            );
        }

        $this->ownerslotworkflowAuthorize($actor, MediaAbility::Associate, $candidate, $owner);
        $previous = $currentAssociation instanceof MediaAssociation
            ? $this->ownerslotworkflowLockedMedia($actor, $currentAssociation->media_id)
            : null;
        $resultAssociation = $this->attach($actor,
            media: $candidate,
            model: $owner,
            collection: $slot,
            metadata: ['slot' => $slot],
        );

        if ($previous instanceof Media) {
            $this->detach($actor, $previous, $owner, $slot);

            if ($slotDefinition->isExclusive()
                && ! $previous->trashed()
                && ! MediaAssociation::query()
                    ->where('media_id', $previous->id)
                    ->exists()) {
                $this->deleteMedia->delete($actor, $previous, owner: $owner);
            }
        }

        return $this->ownerslotworkflowProject($actor, $candidate, $resultAssociation);
    }

    /**
     * Require the upload boundary to return a distinct destination-compliant identity.
     */
    private function ownerslotcopyworkflowAssertCanonicalCopy(Authenticatable|SystemActor $actor,
        Media $source,
        Media $copy,
        MediaSlot $slot,
    ): void {
        if (hash_equals($source->id, $copy->id)
            || hash_equals($source->hash, $copy->hash)
            || ! hash_equals(Str::lower($source->digest), Str::lower($copy->digest))
            || $source->size !== $copy->size
            || $source->filename !== $copy->filename
            || $copy->disk !== $slot->resolveDisk()
            || (bool) $copy->is_public !== $slot->isPublic) {
            throw new MediaUploadException(
                'Canonical owner-slot copy ingestion returned an invalid Media identity.',
            );
        }
    }

    /**
     * Recover a committed cross-connection mutation from its durable Media checkpoint.
     */
    private function ownerslotcopyworkflowRecoverCheckpoint(
        Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        MediaOwnerSlotOperationClaim $claim,
        bool $lockForUpdate = false,
    ): ?Media {
        if ($claim->resultMediaId === null) {
            return null;
        }

        $mediaQuery = MediaModelRegistry::query()->withTrashed()->whereKey($claim->resultMediaId);

        if ($lockForUpdate) {
            $mediaQuery->lockForUpdate();
        }

        $media = $mediaQuery->first();

        if (! $media instanceof Media) {
            return null;
        }

        $this->ownerslotworkflowAuthorize($actor, MediaAbility::Associate, $media, $owner);

        if ($claim->resultPayload !== null) {
            return $this->restoreSlotResult($claim->resultPayload);
        }

        $association = $this->resolver->currentAssociation(
            $owner,
            $slot,
            $lockForUpdate,
        );

        if (! $association instanceof MediaAssociation
            || ! hash_equals($association->media_id, $claim->resultMediaId)) {
            throw new LogicException(
                'The checkpointed Media copy was displaced before its immutable result was recorded.',
            );
        }

        $this->ownerslotworkflowAssertAvailable($actor, $media);

        return $this->ownerslotworkflowProject($actor, $media, $association);
    }

    /**
     * Complete a split-ledger copy at the durable Media root transaction boundary.
     */
    private function ownerslotcopyworkflowCompleteSplitCopyClaim(Authenticatable|SystemActor $actor,
        MediaOwnerSlotOperationClaim $claim,
        Media $result,
        MediaOwnerSlotMutationState $mutationState,
    ): void {
        if ($mutationState->committed()) {
            $this->idempotency->complete(
                $claim,
                $result->id,
                $this->slotSnapshot($result),
            );

            return;
        }

        MediaModelRegistry::newInstance()->getConnection()->afterCommit(function () use ($claim, $result): void {
            try {
                $this->idempotency->complete(
                    $claim,
                    $result->id,
                    $this->slotSnapshot($result),
                );
            } catch (Throwable $claimFailure) {
                report($claimFailure);
            }
        });
        DB::afterRollBack(function () use ($actor, $claim): void {
            $this->ownerslotworkflowRecordFailure($actor, $claim, 'copy_failed');
        });
    }

    /**
     * Resolve a completed idempotent result without rerunning lifecycle effects.
     */
    private function ownerslotcopyworkflowReplayProjection(Authenticatable|SystemActor $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        MediaOwnerSlotOperationClaim $claim,
    ): Media {
        if ($claim->resultPayload !== null) {
            return $this->restoreSlotResult($claim->resultPayload);
        }

        if ($claim->resultMediaId === null) {
            throw new LogicException(
                'A completed Media copy claim has no result Media identifier.',
            );
        }

        $association = $this->resolver->currentAssociation($owner, $slot);

        if (! $association instanceof MediaAssociation
            || ! hash_equals($association->media_id, $claim->resultMediaId)) {
            throw new LogicException(
                'The Media owner-slot copy did not produce the expected association.',
            );
        }

        return $this->ownerslotworkflowProject($actor,
            $this->ownerslotworkflowAvailableMedia($actor, $claim->resultMediaId),
            $association,
        );
    }

    /**
     * Resolve a Media record, retaining tombstones for replay authorization.
     */
    private function ownerslotcopyworkflowMedia(Authenticatable|SystemActor $actor, string $mediaId): Media
    {
        $media = MediaModelRegistry::query()->withTrashed()->find($mediaId);

        if (! $media instanceof Media) {
            throw new MediaUploadException("Media [{$mediaId}] does not exist.");
        }

        return $media;
    }

    /**
     * Verify materialized bytes against the locked source identity.
     */
    private function ownerslotcopyworkflowVerifyMaterializedSource(Authenticatable|SystemActor $actor, Media $source, string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new MediaUploadException(
                "Media source object [{$source->id}] is not readable.",
            );
        }

        $digest = hash_file('sha256', $path);

        if (! is_string($digest) || ! hash_equals(Str::lower($source->digest), $digest)) {
            throw new MediaUploadException(
                "Media source checksum does not match [{$source->id}].",
            );
        }

        $size = filesize($path);

        if (! is_int($size) || $size !== $source->size) {
            throw new MediaUploadException(
                "Media source size does not match [{$source->id}].",
            );
        }
    }

    /**
     * Preserve normalized user-facing classification tags only.
     *
     * @return list<string>
     */
    private function ownerslotcopyworkflowCopyTags(Authenticatable|SystemActor $actor, Media $source): array
    {
        $tags = [];

        foreach ($source->tags ?? [] as $tag) {
            $tag = trim($tag);

            if ($tag !== '' && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Copy scalar metadata only when its exact key is explicitly approved.
     *
     * @return array<string, scalar|null>
     */
    private function ownerslotcopyworkflowCopyMetadata(Authenticatable|SystemActor $actor, Media $source): array
    {
        $allowed = [];

        foreach (MediaConfiguration::stringList(
            'nvl-media.owner_slots.copy.metadata_keys',
            self::DEFAULT_METADATA_KEYS,
        ) as $key) {
            $key = trim($key);

            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,99}$/D', $key) === 1) {
                $allowed[$key] = true;
            }
        }

        $copied = [];

        foreach ($source->metadata ?? [] as $key => $value) {
            if (! isset($allowed[$key])
                || (! is_scalar($value) && $value !== null)
                || (is_float($value) && ! is_finite($value))) {
                continue;
            }

            $copied[$key] = $value;
        }

        ksort($copied, SORT_STRING);

        return $copied;
    }

    /** @return Collection<int, Media> */
    private function ownerAssets(Authenticatable|SystemActor $actor, Model $owner, ?string $collection = null): Collection
    {
        $owner = $this->owners->resolve($owner);
        $ownerId = $owner->getKey();
        if (! is_int($ownerId) && ! is_string($ownerId)) {
            throw new InvalidArgumentException('Media owners require a scalar key.');
        }
        $associations = MediaAssociation::query()->where('associable_type', $owner->getMorphClass())->where('associable_id', (string) $ownerId);
        if ($collection !== null) {
            $associations->where('collection', $collection);
        }
        $assets = MediaModelRegistry::query()->whereIn('id', $associations->select('media_id'))->get();
        foreach ($assets as $media) {
            $this->access->authorize($actor, MediaAbility::View, $media, $owner);
        }

        return $assets;
    }

    /** Restore the durable native model result without replaying its mutation.
     * @param  array<string, mixed>  $snapshot
     */
    private function restoreSlotResult(array $snapshot): Media
    {
        $relations = [];
        foreach (['translations', 'image_variations', 'pivot'] as $key) {
            if (array_key_exists($key, $snapshot)) {
                $relations[$key] = $snapshot[$key];
                unset($snapshot[$key]);
            }
        }
        $media = MediaModelRegistry::newInstance()->forceFill($snapshot);
        $media->exists = true;
        $media->syncOriginal();
        foreach (['translations' => MediaTranslation::class, 'image_variations' => MediaImageVariation::class] as $key => $class) {
            if (isset($relations[$key])) {
                $rows = $relations[$key];
                if (! is_array($rows) || ! array_is_list($rows)) {
                    throw new InvalidArgumentException('Media slot replay relations must be lists.');
                }
                $models = [];
                foreach ($rows as $attributes) {
                    $models[] = (new $class)->forceFill($this->slotSnapshotAttributes($attributes));
                }
                $media->setRelation($key === 'image_variations' ? 'imageVariations' : $key, (new $class)->newCollection($models));
            }
        }
        if (isset($relations['pivot'])) {
            $media->setRelation('pivot', (new MediaAssociation)->forceFill($this->slotSnapshotAttributes($relations['pivot'])));
        }

        return $media;
    }

    /** Capture the native durable replay result using named model attributes.
     * @return array<string, mixed>
     */
    private function slotSnapshot(Media $media): array
    {
        return $this->slotSnapshotAttributes($media->toArray());
    }

    /** Validate persisted replay maps before hydrating model attributes.
     * @return array<string, mixed>
     */
    private function slotSnapshotAttributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            throw new InvalidArgumentException('Media slot replay attributes must be a map.');
        }
        $normalized = [];
        foreach ($attributes as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Media slot replay attributes require string keys.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
