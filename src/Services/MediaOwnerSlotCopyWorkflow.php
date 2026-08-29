<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\UploadMediaContract;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Support\MediaOwnerSlotMutationState;
use Nvl\Media\Support\MediaOwnerSlotOperationClaim;
use Nvl\Media\Support\MediaTemporaryLocalFile;
use Throwable;

/**
 * Orchestrates verified Media copies and their destination-slot lifecycle.
 */
final readonly class MediaOwnerSlotCopyWorkflow
{
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

    public function __construct(
        private MediaOwnerSlotResolver $resolver,
        private MediaStagingPolicy $stagingPolicy,
        private MediaAuthorization $authorization,
        private AttachMediaContract $attachMedia,
        private DetachMediaContract $detachMedia,
        private DeleteMediaContract $deleteMedia,
        private UploadMediaContract $uploadMedia,
        private MediaMutationLock $mutationLock,
        private MediaOwnerSlotIdempotency $idempotency,
        private MediaLibraryItemDataFactory $items,
        private MediaLocalFileMaterializer $materializer,
    ) {}

    /**
     * Copy one authorized source through canonical destination ingestion.
     */
    public function copy(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        string $sourceMediaId,
        ?string $idempotencyKey = null,
    ): MediaLibraryItem {
        $slotDefinition = $this->resolver->slot($owner, $slot);
        $slot = $slotDefinition->name;
        $sourceMediaId = $this->mediaId($sourceMediaId);
        $source = $this->media($sourceMediaId);
        $this->authorize($actor, MediaAbility::View, $source, $owner);
        $this->authorize($actor, MediaAbility::Associate, $source, $owner);
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
                return $this->replayProjection($owner, $slot, $claim);
            }

            if ($claim instanceof MediaOwnerSlotOperationClaim) {
                $recovered = $this->recoverCheckpoint($actor, $owner, $slot, $claim);

                if ($recovered instanceof MediaLibraryItem) {
                    $mutationState->markCommitted();
                    $this->idempotency->complete(
                        $claim,
                        $recovered->id,
                        $recovered->toArray(),
                    );

                    return $recovered;
                }
            }

            $this->assertAvailable($source);
            $completeInsideMutation = $claim instanceof MediaOwnerSlotOperationClaim
                && $this->ledgerSharesMediaConnection();
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
                ): MediaLibraryItem {
                    if ($claim instanceof MediaOwnerSlotOperationClaim) {
                        $recovered = DB::transaction(function () use (
                            $actor,
                            $claim,
                            $mutationState,
                            $owner,
                            $slot,
                        ): ?MediaLibraryItem {
                            $this->idempotency->renew($claim);
                            $recovered = $this->recoverCheckpoint(
                                $actor,
                                $owner,
                                $slot,
                                $claim,
                                lockForUpdate: true,
                            );

                            if ($recovered instanceof MediaLibraryItem) {
                                $mutationState->markCommitted();
                            }

                            return $recovered;
                        });

                        if ($recovered instanceof MediaLibraryItem) {
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
                        fn (): MediaLibraryItem => $this->copyUnderAcquiredLocks(
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
                    $result->toArray(),
                );
            }

            if ($claim instanceof MediaOwnerSlotOperationClaim
                && ! $completeInsideMutation) {
                $this->completeSplitCopyClaim(
                    $claim,
                    $result,
                    $mutationState,
                );
            }

            return $result;
        } catch (Throwable $exception) {
            if (! $mutationState->committed()) {
                $this->recordFailure($claim);
            }

            throw $exception;
        }
    }

    /**
     * Materialize and verify the source before entering canonical ingestion.
     */
    private function copyUnderAcquiredLocks(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        MediaSlot $slotDefinition,
        string $sourceMediaId,
        ?MediaOwnerSlotOperationClaim $claim,
        bool $completeInsideMutation,
        MediaOwnerSlotMutationState $mutationState,
    ): MediaLibraryItem {
        $source = $this->availableMedia($sourceMediaId);

        try {
            $lease = $this->materializer->lease($source->disk, $source->buildPath());
        } catch (Throwable $exception) {
            throw new MediaUploadException(
                "Media source object [{$source->id}] could not be materialized.",
                previous: $exception,
            );
        }

        try {
            return DB::transaction(
                fn (): MediaLibraryItem => $this->copyInTransaction(
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
    private function copyInTransaction(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        MediaSlot $slotDefinition,
        string $sourceMediaId,
        MediaTemporaryLocalFile $lease,
        ?MediaOwnerSlotOperationClaim $claim,
        bool $completeInsideMutation,
        MediaOwnerSlotMutationState $mutationState,
    ): MediaLibraryItem {
        DB::afterCommit(function () use (
            $mutationState,
        ): void {
            $mutationState->markCommitted();
        });
        $this->lockOwner($owner);
        $source = $this->lockedMedia($sourceMediaId);
        $this->assertAvailable($source);
        $this->authorize($actor, MediaAbility::View, $source, $owner);
        $this->authorize($actor, MediaAbility::Associate, $source, $owner);
        $this->verifyMaterializedSource($source, $lease->path());

        $copy = $this->uploadMedia->execute(
            file: new UploadedFile(
                $lease->path(),
                $source->filename,
                $source->mime_type,
                null,
                true,
            ),
            disk: $slotDefinition->disk,
            model: $owner,
            slot: $slotDefinition,
            fileName: $source->filename,
            isPublic: $slotDefinition->isPublic,
            tags: $this->copyTags($source),
            metadata: $this->copyMetadata($source),
            allowDuplicates: false,
            deduplicateExisting: false,
            skipAutoVariations: true,
            uploadedBy: $this->actorId($actor),
            uploadedByType: $this->actorType($actor),
        );
        $copy = $this->lockedMedia($copy->id);
        $this->assertCanonicalCopy($source, $copy, $slotDefinition);

        if ($claim instanceof MediaOwnerSlotOperationClaim) {
            $this->idempotency->checkpoint($claim, $copy->id);
        }

        $result = $this->replaceCanonicalCopy(
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
                $result->toArray(),
            );

            if ($completeInsideMutation) {
                $this->idempotency->complete(
                    $claim,
                    $result->id,
                    $result->toArray(),
                );
            }
        }

        return $result;
    }

    /**
     * Replace the destination using a new canonical, unassociated upload.
     */
    private function replaceCanonicalCopy(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        MediaSlot $slotDefinition,
        string $candidateId,
    ): MediaLibraryItem {
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

        $this->authorize($actor, MediaAbility::Associate, $candidate, $owner);
        $previous = $currentAssociation instanceof MediaAssociation
            ? $this->lockedMedia($currentAssociation->media_id)
            : null;
        $resultAssociation = $this->attachMedia->execute(
            media: $candidate,
            model: $owner,
            collection: $slot,
            metadata: ['slot' => $slot],
        );

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
     * Require the upload boundary to return a distinct destination-compliant identity.
     */
    private function assertCanonicalCopy(
        Media $source,
        Media $copy,
        MediaSlot $slot,
    ): void {
        if (hash_equals($source->id, $copy->id)
            || hash_equals($source->hash, $copy->hash)
            || ! hash_equals(Str::lower($source->digest), Str::lower($copy->digest))
            || $source->size !== $copy->size
            || $source->filename !== $copy->filename
            || $copy->disk !== $slot->disk
            || (bool) $copy->is_public !== $slot->isPublic) {
            throw new MediaUploadException(
                'Canonical owner-slot copy ingestion returned an invalid Media identity.',
            );
        }
    }

    /**
     * Recover a committed cross-connection mutation from its durable Media checkpoint.
     */
    private function recoverCheckpoint(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        MediaOwnerSlotOperationClaim $claim,
        bool $lockForUpdate = false,
    ): ?MediaLibraryItem {
        if ($claim->resultMediaId === null) {
            return null;
        }

        $mediaQuery = Media::withTrashed()->whereKey($claim->resultMediaId);

        if ($lockForUpdate) {
            $mediaQuery->lockForUpdate();
        }

        $media = $mediaQuery->first();

        if (! $media instanceof Media) {
            return null;
        }

        $this->authorize($actor, MediaAbility::Associate, $media, $owner);

        if ($claim->resultPayload !== null) {
            return MediaLibraryItem::from($claim->resultPayload);
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

        $this->assertAvailable($media);

        return $this->project($media, $association);
    }

    /**
     * Complete a split-ledger copy at the durable Media root transaction boundary.
     */
    private function completeSplitCopyClaim(
        MediaOwnerSlotOperationClaim $claim,
        MediaLibraryItem $result,
        MediaOwnerSlotMutationState $mutationState,
    ): void {
        if ($mutationState->committed()) {
            $this->idempotency->complete(
                $claim,
                $result->id,
                $result->toArray(),
            );

            return;
        }

        DB::afterCommit(function () use ($claim, $result): void {
            try {
                $this->idempotency->complete(
                    $claim,
                    $result->id,
                    $result->toArray(),
                );
            } catch (Throwable $claimFailure) {
                report($claimFailure);
            }
        });
        DB::afterRollBack(function () use ($claim): void {
            $this->recordFailure($claim);
        });
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

        return $this->project(
            $this->availableMedia($claim->resultMediaId),
            $association,
        );
    }

    /**
     * Resolve a non-deleted, usable Media record.
     */
    private function availableMedia(string $mediaId): Media
    {
        $media = $this->media($mediaId);

        $this->assertAvailable($media);

        return $media;
    }

    /**
     * Resolve a Media record, retaining tombstones for replay authorization.
     */
    private function media(string $mediaId): Media
    {
        $media = Media::withTrashed()->find($mediaId);

        if (! $media instanceof Media) {
            throw new MediaUploadException("Media [{$mediaId}] does not exist.");
        }

        return $media;
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
            throw new MediaUploadException("Media [{$mediaId}] does not exist.");
        }

        return $media;
    }

    /**
     * Reject a tombstoned or unusable Media record.
     */
    private function assertAvailable(Media $media): void
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
     * Serialize an initially empty slot through its durable owner row when possible.
     */
    private function lockOwner(Model&HasMedia $owner): void
    {
        if ($owner->getConnection()->getName()
            !== (new MediaAssociation)->getConnection()->getName()) {
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
     * Verify materialized bytes against the locked source identity.
     */
    private function verifyMaterializedSource(Media $source, string $path): void
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
    private function copyTags(Media $source): array
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
    private function copyMetadata(Media $source): array
    {
        $allowed = [];

        foreach (MediaConfiguration::stringList(
            'media.owner_slots.copy.metadata_keys',
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

    /**
     * Load bounded projection relations and preserve the selected association.
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
     * Normalize one package-owned source Media UUID.
     */
    private function mediaId(string $mediaId): string
    {
        $mediaId = Str::lower(trim($mediaId));

        if (! Str::isUuid($mediaId)) {
            throw new InvalidArgumentException(
                'Media owner-slot copies require a valid Media UUID.',
            );
        }

        return $mediaId;
    }

    /**
     * Resolve an attributable actor identifier for the copied Media row.
     */
    private function actorId(MediaActorData $actor): ?string
    {
        if (! is_int($actor->id) && ! is_string($actor->id)) {
            return null;
        }

        $id = trim((string) $actor->id);

        return $id !== '' ? $id : null;
    }

    /**
     * Resolve an attributable actor type for the copied Media row.
     */
    private function actorType(MediaActorData $actor): ?string
    {
        if (! is_string($actor->type)) {
            return null;
        }

        $type = trim($actor->type);

        return $type !== '' ? $type : null;
    }

    /**
     * Determine whether ledger completion participates in the Media transaction.
     */
    private function ledgerSharesMediaConnection(): bool
    {
        return (new MediaOwnerSlotOperation)->getConnection()->getName()
            === (new Media)->getConnection()->getName();
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
            $this->idempotency->fail($claim, 'copy_failed');
        } catch (Throwable $claimFailure) {
            report($claimFailure);
        }
    }
}
