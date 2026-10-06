<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Models\MediaTenantGrant;

/**
 * Revokes future imports without affecting committed tenant copies.
 *
 * @api
 */
interface RevokeMediaTenantGrantContract
{
    /** Execute the revoke media tenant grant workflow. */
    public function execute(string $grantId, int $expectedRevision): MediaTenantGrant;
}
