<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Contracts\MediaCatalogAccess;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Catalog capability issuance is unavailable until the host supplies a policy. @internal */
final readonly class DenyMediaCatalogAccess implements MediaCatalogAccess
{
    public function assertCanGrant(string $sourceId, int $revision): void
    {
        throw new OwnershipViolation('A host catalog grant policy is required.');
    }

    public function assertCanRevoke(string $grantId): void
    {
        throw new OwnershipViolation('A host catalog revocation policy is required.');
    }
}
