<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Support\MediaActors;
use Nvl\Support\Enums\SystemActor;

/**
 * Evaluates consumer authorization and optional cross-owner privileges consistently.
 */
final readonly class MediaAccessService
{
    public function __construct(
        private MediaAuthorization $authorization,
        private MediaPrivilegedAccess $privilegedAccess,
    ) {}

    public function authorize(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): void
    {
        if (! $this->allows($actor, $ability, $media, $owner)) {
            throw new AuthorizationException("The actor cannot {$ability->value} this media.");
        }
    }

    public function allows(
        Authenticatable|SystemActor $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool {
        MediaActors::assert($actor);
        if ($actor instanceof Authenticatable && $this->privilegedAccess->allows($actor, $ability)) {
            return true;
        }

        return $this->authorization->allows(
            $actor,
            $ability,
            $media,
            $owner,
        );
    }
}
