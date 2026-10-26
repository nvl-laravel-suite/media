<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Nvl\Media\Tests\Fixtures\CatalogOwnershipMedia;

abstract class CatalogMediaTestCase extends OwnershipTestCase
{
    protected function mediaClass(): string
    {
        return CatalogOwnershipMedia::class;
    }
}
