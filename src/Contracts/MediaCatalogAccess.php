<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

/**
 * Host policy for issuing or revoking central-copy capabilities in the current admitted owner.
 *
 * @api
 */
interface MediaCatalogAccess
{
    public function assertCanGrant(string $sourceId, int $revision): void;

    public function assertCanRevoke(string $grantId): void;
}
