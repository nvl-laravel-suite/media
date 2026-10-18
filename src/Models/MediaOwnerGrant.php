<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/**
 * @property-read string|null $owner_scope_key Technical immutable partition.
 * @property-read string|null $owner_reference Captured scalar reference.
 *
 * Recipient-owned copy capability; the historical tenant grant API remains a V1 bridge. @api */
final class MediaOwnerGrant extends MediaTenantGrant implements Ownable
{
    use HasOwnership;
}
