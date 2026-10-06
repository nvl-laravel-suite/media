<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Support\Owners\OwnerBatch;

/** Explicit query-free owner/association and asset View authorization.
 *
 * @api
 */
interface MediaBatchAuthorization
{
    /** Admit the complete canonical owner set using loaded facts only. */
    public function authorizeOwners(OwnerBatch $batch, MediaActorData $actor): void;

    /** Constrain association visibility and owner-dependent asset View without queries.
     * @param  Builder<MediaAssociation>  $query
     */
    public function scopeAssociations(Builder $query, OwnerBatch $batch, MediaActorData $actor): void;

    /** Constrain asset View visibility for the complete admitted owner batch.
     * @param  Builder<Media>  $query
     */
    public function scopeMedia(Builder $query, OwnerBatch $batch, MediaActorData $actor): void;

    /** Express complete host owner/registered-slot/association/associated-asset View access in SQL.
     * Throw when owner-dependent asset authorization cannot be expressed in SQL.
     *
     * @param  Builder<MediaAssociation>  $query
     */
    public function scopeHostAssociations(Builder $query, Model $ownerPrototype, MediaActorData $actor, string $slot): void;
}
