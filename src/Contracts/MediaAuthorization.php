<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Support\Enums\SystemActor;

/**
 * Consumer-owned authorization policy for media capabilities.
 *
 * @api
 */
interface MediaAuthorization
{
    /**
     * Determine whether an actor may perform a media ability.
     */
    public function allows(
        Authenticatable|SystemActor $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool;
}
