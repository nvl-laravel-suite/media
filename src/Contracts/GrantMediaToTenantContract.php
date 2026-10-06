<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Grants one exact platform Media revision to an active recipient tenant.
 *
 * @api
 */
interface GrantMediaToTenantContract
{
    /** Execute the grant media to tenant workflow. */
    public function execute(string $mediaId, TenantId $recipient, int $sourceRevision): MediaTenantGrant;
}
