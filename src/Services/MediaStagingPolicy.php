<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaConfiguration;

/**
 * Validates existing Media against slot policy and identifies removable staging associations.
 */
final readonly class MediaStagingPolicy
{
    public function __construct(
        private MediaAuthorization $authorization,
    ) {}

    /**
     * Validate an existing record and return only associations approved for staging cleanup.
     *
     * @param  Collection<int, MediaAssociation>  $associations
     * @return Collection<int, MediaAssociation>
     *
     * @throws AuthorizationException
     * @throws FileUnacceptableForCollection
     * @throws MediaUploadException
     */
    public function removableAssociations(
        MediaActorData $actor,
        Model&HasMedia $owner,
        MediaSlot $slot,
        Media $media,
        Collection $associations,
    ): Collection {
        $this->assertFitsSlot($media, $slot);

        if ($media->is_public && $slot->isReusable()) {
            if (! $this->authorization->allows(
                $actor,
                MediaAbility::Reuse,
                $media,
                $owner,
            )) {
                throw new AuthorizationException(
                    'The actor is not authorized to reuse this public Media asset.',
                );
            }

            return new Collection;
        }

        if ($associations->isEmpty()) {
            if ($actor->system || $this->matchesUploader($actor, $media)) {
                return $associations;
            }

            throw new AuthorizationException(
                'The selected staged media is not owned by the actor.',
            );
        }

        if ($this->allBelongToActor($associations, $actor)) {
            return $associations;
        }

        if (! $this->belongsToUploaderIdentity($associations, $media)) {
            throw new AuthorizationException(
                'The selected Media is associated with non-staging owners.',
            );
        }

        if (! $this->authorization->allows(
            $actor,
            MediaAbility::ManageStaging,
            $media,
            $owner,
        )) {
            throw new AuthorizationException(
                'The actor requires manage staging authorization for this Media asset.',
            );
        }

        return $associations;
    }

    /**
     * Enforce constraints that can be proven from a persisted Media record.
     *
     * @throws FileUnacceptableForCollection
     * @throws MediaUploadException
     */
    public function assertFitsSlot(Media $media, MediaSlot $slot): void
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

        if ((bool) $media->is_public !== $slot->isPublic) {
            throw new FileUnacceptableForCollection(
                "Media visibility is not compatible with slot [{$slot->name}].",
            );
        }

        if ($slot->acceptedMimeTypes !== []
            && ! in_array($media->mime_type, $slot->acceptedMimeTypes, true)) {
            throw new FileUnacceptableForCollection(
                "File MIME type [{$media->mime_type}] is not accepted by slot [{$slot->name}].",
            );
        }

        $globalLimit = MediaConfiguration::integer(
            'media.max_file_size',
            10 * 1024 * 1024,
            1,
        );
        $limit = $slot->maxFileSize === null
            ? $globalLimit
            : min($globalLimit, $slot->maxFileSize);

        if ($media->size > $limit) {
            throw new FileUnacceptableForCollection(
                "File size [{$media->size}] exceeds maximum [{$limit}] for slot [{$slot->name}].",
            );
        }

        if ($slot->fileAcceptor !== null) {
            throw new FileUnacceptableForCollection(
                "Existing Media cannot satisfy the custom file validator for slot [{$slot->name}]; upload directly into the slot instead.",
            );
        }
    }

    private function matchesUploader(MediaActorData $actor, Media $media): bool
    {
        return $this->identifiable($actor)
            && hash_equals((string) $media->uploaded_by_type, (string) $actor->type)
            && hash_equals((string) $media->uploaded_by, (string) $actor->id);
    }

    /**
     * @param  Collection<int, MediaAssociation>  $associations
     */
    private function allBelongToActor(
        Collection $associations,
        MediaActorData $actor,
    ): bool {
        if (! $this->identifiable($actor)) {
            return false;
        }

        return $associations->every(
            static fn (MediaAssociation $association): bool => hash_equals(
                $association->associable_type,
                (string) $actor->type,
            ) && hash_equals(
                (string) $association->associable_id,
                (string) $actor->id,
            ),
        );
    }

    /**
     * @param  Collection<int, MediaAssociation>  $associations
     */
    private function belongsToUploaderIdentity(
        Collection $associations,
        Media $media,
    ): bool {
        if (! is_string($media->uploaded_by_type)
            || $media->uploaded_by_type === ''
            || ! is_string($media->uploaded_by)
            || $media->uploaded_by === '') {
            return false;
        }

        return $associations->every(
            static fn (MediaAssociation $association): bool => hash_equals(
                $media->uploaded_by_type,
                $association->associable_type,
            ) && hash_equals(
                $media->uploaded_by,
                (string) $association->associable_id,
            ),
        );
    }

    private function identifiable(MediaActorData $actor): bool
    {
        return is_string($actor->type)
            && $actor->type !== ''
            && (is_int($actor->id) || is_string($actor->id))
            && (string) $actor->id !== '';
    }
}
