<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Exceptions\MediaBatchReadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Support\Owners\OwnerBatch;

/** Fails closed until the host explicitly binds an SQL authorization adapter. */
final class UnsupportedMediaBatchAuthorization implements MediaBatchAuthorization
{
    /** Reject unsupported owner admission. */
    public function authorizeOwners(OwnerBatch $batch, MediaActorData $actor): void
    {
        throw new MediaBatchReadException('Media batch reads require an explicit MediaBatchAuthorization adapter.');
    }

    /** Reject unsupported association visibility. @param Builder<MediaAssociation> $query */
    public function scopeAssociations(Builder $query, OwnerBatch $batch, MediaActorData $actor): void
    {
        $this->authorizeOwners($batch, $actor);
    }

    /** Reject unsupported asset View visibility. @param Builder<Media> $query */
    public function scopeMedia(Builder $query, OwnerBatch $batch, MediaActorData $actor): void
    {
        $this->authorizeOwners($batch, $actor);
    }

    /** Reject unsupported host visibility. @param Builder<MediaAssociation> $query */
    public function scopeHostAssociations(Builder $query, Model $ownerPrototype, MediaActorData $actor, string $slot): void
    {
        throw new MediaBatchReadException('Media host queries require a complete MediaBatchAuthorization SQL View adapter.');
    }
}
