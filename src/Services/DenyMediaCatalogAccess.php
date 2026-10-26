<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Media\Contracts\MediaCatalogAccess;
use Nvl\Media\Support\MediaActors;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Catalog capability issuance is unavailable until the host supplies a policy.
 *
 * @internal
 */
final readonly class DenyMediaCatalogAccess implements MediaCatalogAccess
{
    public function assertCanGrant(Authenticatable|SystemActor $actor, string $sourceId, int $revision): void
    {
        $actor = MediaActors::assert($actor);
        throw new OwnershipViolation('A host catalog grant policy is required.');
    }

    public function assertCanRevoke(Authenticatable|SystemActor $actor, string $grantId): void
    {
        $actor = MediaActors::assert($actor);
        throw new OwnershipViolation('A host catalog revocation policy is required.');
    }
}
