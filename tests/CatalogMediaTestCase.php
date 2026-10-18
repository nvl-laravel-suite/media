<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Nvl\Media\Tests\Fixtures\CatalogOwnershipMedia;

abstract class CatalogMediaTestCase extends OwnershipTestCase
{
    protected function adoptOwnership(): void
    {
        (require __DIR__.'/../database/tenancy/2026_09_16_100002_nvl_media_create_media_tenant_grants.php')->up();
        parent::adoptOwnership();
    }

    protected function mediaClass(): string
    {
        return CatalogOwnershipMedia::class;
    }
}
