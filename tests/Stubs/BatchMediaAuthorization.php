<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Exceptions\MediaBatchReadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Support\Owners\OwnerBatch;

/** Query-free explicit test View policy with independently denied associations and assets. */
class BatchMediaAuthorization implements MediaBatchAuthorization
{
    /** @var list<string> */
    public array $deniedAssets = [];

    /** @var list<string> */
    public array $deniedAssociations = [];

    public bool $denyOwners = false;

    public bool $unsupportedHost = false;

    /** @var list<Model> */
    public array $admitted = [];

    /** Require canonical loaded owner facts. */
    public function authorizeOwners(OwnerBatch $batch, MediaActorData $actor): void
    {
        $this->admitted = $batch->owners();
        if ($this->denyOwners) {
            throw new AuthorizationException('Denied owner batch.');
        }
    }

    /** Restrict associations by explicit loaded policy facts.
     * @param  Builder<MediaAssociation>  $query
     */
    public function scopeAssociations(Builder $query, OwnerBatch $batch, MediaActorData $actor): void
    {
        $query->whereNotIn('id', $this->deniedAssociations);
    }

    /** Restrict assets; an empty batch is not a wildcard.
     * @param  Builder<Media>  $query
     */
    public function scopeMedia(Builder $query, OwnerBatch $batch, MediaActorData $actor): void
    {
        if ($batch->isEmpty()) {
            throw new MediaBatchReadException('Asset View needs loaded owners.');
        }
        $query->whereNotIn('id', $this->deniedAssets);
    }

    /** Express complete owner/association/asset View visibility in host SQL.
     * @param  Builder<MediaAssociation>  $query
     */
    public function scopeHostAssociations(Builder $query, Model $ownerPrototype, MediaActorData $actor, string $slot): void
    {
        if ($this->unsupportedHost || $this->denyOwners) {
            throw new MediaBatchReadException('Bind a complete host View adapter.');
        }
        $query->whereNotIn('id', $this->deniedAssociations)
            ->whereHas('media', fn (Builder $media) => $media->whereNotIn('id', $this->deniedAssets));
    }
}
