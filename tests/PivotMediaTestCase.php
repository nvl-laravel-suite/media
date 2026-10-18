<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Nvl\Media\Tests\Fixtures\PivotOwnershipMedia;

abstract class PivotMediaTestCase extends OwnershipTestCase
{
    protected function mediaClass(): string
    {
        return PivotOwnershipMedia::class;
    }
}
