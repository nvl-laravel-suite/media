<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Nvl\Media\Models\Media;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Consumer-defined concrete primary ownership pivot using unchanged native keys. */
final class PivotOwnershipMedia extends Media
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::pivot(OwnershipCompany::class, 'media_company_ownership', 'asset_id', 'company_id');
    }
}
