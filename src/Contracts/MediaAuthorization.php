<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;

/**
 * Consumer-owned authorization policy for media capabilities.
 */
interface MediaAuthorization
{
    /**
     * Determine whether an actor may perform a media ability.
     */
    public function allows(
        MediaActorData $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool;
}
