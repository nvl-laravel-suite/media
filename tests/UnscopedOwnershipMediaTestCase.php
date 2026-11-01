<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Nvl\Ownership\Providers\OwnershipServiceProvider;

abstract class UnscopedOwnershipMediaTestCase extends MediaTestCase
{
    protected function getPackageProviders($app): array
    {
        return [OwnershipServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set(['nvl-ownership.enabled' => true, 'nvl-ownership.migrations.enabled' => false]);
    }
}
