<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Nvl\Media\Models\Media;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Consumer model retaining its native integer company foreign key. */
class OwnershipMedia extends Media
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }
}
