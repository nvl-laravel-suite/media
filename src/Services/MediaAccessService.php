<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;

/**
 * Evaluates consumer authorization and optional cross-owner privileges consistently.
 */
final readonly class MediaAccessService
{
    public function __construct(
        private MediaAuthorization $authorization,
        private MediaPrivilegedAccess $privilegedAccess,
    ) {}

    public function allows(
        Authenticatable $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool {
        if ($this->privilegedAccess->allows($actor, $ability)) {
            return true;
        }

        return $this->authorization->allows(
            MediaActorData::fromAuthenticatable($actor),
            $ability,
            $media,
            $owner,
        );
    }
}
