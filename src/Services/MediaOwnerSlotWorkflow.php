<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaOwnerSlotOperationClaim;
use Throwable;

/**
 * Deliberately orchestrates Media Actions, authorization, locks, and lifecycle transitions for owner slots.
 */
final readonly class MediaOwnerSlotWorkflow
{
    public function __construct(
        private MediaOwnerSlotResolver $resolver,
        private MediaStagingPolicy $stagingPolicy,
        private MediaAuthorization $authorization,
        private AttachMediaContract $attachMedia,
        private DetachMediaContract $detachMedia,
        private DeleteMediaContract $deleteMedia,
        private MediaMutationLock $mutationLock,
        private MediaOwnerSlotIdempotency $idempotency,
        private MediaLibraryItemDataFactory $items,
    ) {}

    /**
     * Return the authorized DTO for a registered owner slot.
     */
    public function get(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
    ): ?MediaLibraryItem {
        $slot = $this->resolver->slot($owner, $slot)->name;
        $association = $this->resolver->currentAssociation($owner, $slot);
        $media = $association instanceof MediaAssociation
            ? $this->availableMedia($association->media_id)
            : null;

        $this->authorize($actor, MediaAbility::View, $media, $owner);

        if (! $association instanceof MediaAssociation || ! $media instanceof Media) {
            return null;
        }

        return $this->project($media, $association);
    }

    /**
     * Replace a registered owner slot with an existing authorized Media record.
     */
    public function replace(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        string $mediaId,
        ?string $idempotencyKey = null,
    ): MediaLibraryItem {
        $slotDefinition = $this->resolver->slot($owner, $slot);
        $slot = $slotDefinition->name;
        $mediaId = $this->mediaId($mediaId);
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
                $replayMedia = Media::withTrashed()->find($mediaId);
                $this->authorize(
                    $actor,
                    MediaAbility::Associate,
                    $replayMedia instanceof Media ? $replayMedia : null,
                    $owner,
                );

                return $this->replayProjection($owner, $slot, $claim);
            }

            $candidate = $this->availableMedia($mediaId);
            $this->authorize(
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
                ): MediaLibraryItem {
                    $current = $this->resolver->currentAssociation($owner, $slot);
                    $mediaIds = [$candidate->id];

                    if ($current instanceof MediaAssociation) {
                        $mediaIds[] = $current->media_id;
                    }

                    return $this->mutationLock->executeMany(
                        $mediaIds,
                        fn (): MediaLibraryItem => DB::transaction(
                            fn (): MediaLibraryItem => $this->replaceInTransaction(
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
                    $result->toArray(),
                );
            }

            return $result;
        } catch (Throwable $exception) {
            $this->recordFailure($claim);

            throw $exception;
        }
    }

    /**
     * Execute the durable association transition under owner and Media locks.
     */
    private function replaceInTransaction(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        MediaSlot $slotDefinition,
        string $candidateId,
    ): MediaLibraryItem {
        $this->lockOwner($owner);
        $candidate = $this->lockedMedia($candidateId);
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
        $this->authorize(
            $actor,
            MediaAbility::Associate,
            $candidate,
            $owner,
        );

        if ($currentAssociation instanceof MediaAssociation
            && hash_equals($currentAssociation->media_id, $candidate->id)) {
            return $this->project($candidate, $currentAssociation);
        }

        $stagingAssociations = $this->stagingPolicy->removableAssociations(
            actor: $actor,
            owner: $owner,
            slot: $slotDefinition,
            media: $candidate,
            associations: $candidateAssociations,
        );
        $previous = $currentAssociation instanceof MediaAssociation
            ? $this->lockedMedia($currentAssociation->media_id)
            : null;

        $resultAssociation = $this->attachMedia->execute(
            media: $candidate,
            model: $owner,
            collection: $slot,
            metadata: ['slot' => $slot],
        );
        $this->detachStagingAssociations($candidate, $stagingAssociations);

        if ($previous instanceof Media) {
            $this->detachMedia->execute($previous, $owner, $slot);

            if ($slotDefinition->isExclusive()
                && ! MediaAssociation::query()
                    ->where('media_id', $previous->id)
                    ->exists()) {
                $this->deleteMedia->execute($previous);
            }
        }

        return $this->project($candidate, $resultAssociation);
    }

    /**
     * Remove only the exact association identities approved by the staging policy.
     *
     * @param  Collection<int, MediaAssociation>  $associations
     */
    private function detachStagingAssociations(
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

            $this->detachMedia->execute(
                $media,
                $stagingOwner,
                $association->collection,
            );
        }
    }

    /**
     * Resolve a non-deleted, usable Media record.
     */
    private function availableMedia(string $mediaId): Media
    {
        $media = Media::withTrashed()->find($mediaId);

        if (! $media instanceof Media) {
            throw new MediaUploadException(
                "Media [{$mediaId}] does not exist.",
            );
        }

        if ($media->trashed()) {
            throw new MediaUploadException(
                "Media [{$mediaId}] is deleted and cannot be associated.",
            );
        }

        if (! $media->isAvailable()) {
            throw new MediaUploadException(
                "Media [{$mediaId}] is not available for association.",
            );
        }

        return $media;
    }

    /**
     * Normalize one package-owned Media UUID before hashing or querying it.
     */
    private function mediaId(string $mediaId): string
    {
        $mediaId = Str::lower(trim($mediaId));

        if (! Str::isUuid($mediaId)) {
            throw new InvalidArgumentException(
                'Media owner-slot replacements require a valid Media UUID.',
            );
        }

        return $mediaId;
    }

    /**
     * Resolve and lock a Media row, including tombstones for explicit rejection.
     */
    private function lockedMedia(string $mediaId): Media
    {
        $media = Media::withTrashed()
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
    private function lockOwner(Model&HasMedia $owner): void
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
     * Load a result projection from the current exact owner association.
     */
    private function currentProjection(
        Model&HasMedia $owner,
        string $slot,
        string $expectedMediaId,
    ): MediaLibraryItem {
        $association = $this->resolver->currentAssociation($owner, $slot);

        if (! $association instanceof MediaAssociation
            || ! hash_equals($association->media_id, $expectedMediaId)) {
            throw new LogicException(
                'The Media owner-slot transition did not produce the expected association.',
            );
        }

        return $this->project(
            $this->availableMedia($expectedMediaId),
            $association,
        );
    }

    /**
     * Resolve a completed idempotent result without rerunning lifecycle effects.
     */
    private function replayProjection(
        Model&HasMedia $owner,
        string $slot,
        MediaOwnerSlotOperationClaim $claim,
    ): MediaLibraryItem {
        if ($claim->resultPayload !== null) {
            return MediaLibraryItem::from($claim->resultPayload);
        }

        if ($claim->resultMediaId === null) {
            throw new LogicException(
                'A completed Media replacement claim has no result Media identifier.',
            );
        }

        return $this->currentProjection($owner, $slot, $claim->resultMediaId);
    }

    /**
     * Load bounded projection relations and preserve the selected slot association.
     */
    private function project(
        Media $media,
        MediaAssociation $association,
    ): MediaLibraryItem {
        $media->loadMissing(['translations', 'imageVariations']);
        $media->loadCount('associations');

        return $this->items->fromAssociation($media, $association);
    }

    /**
     * Enforce the consumer-owned capability boundary.
     *
     * @throws AuthorizationException
     */
    private function authorize(
        MediaActorData $actor,
        MediaAbility $ability,
        ?Media $media,
        Model&HasMedia $owner,
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
    private function recordFailure(?MediaOwnerSlotOperationClaim $claim): void
    {
        if (! $claim instanceof MediaOwnerSlotOperationClaim || $claim->replayed) {
            return;
        }

        try {
            $this->idempotency->fail($claim, 'replace_failed');
        } catch (Throwable $claimFailure) {
            report($claimFailure);
        }
    }
}
