<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;

abstract class UnscopedOwnershipMediaTestCase extends MediaTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = array_filter(parent::getPackageProviders($app), static fn (string $class): bool => $class !== TenancyServiceProvider::class);

        return [OwnershipServiceProvider::class, ...$providers];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set(['nvl-ownership.enabled' => true, 'nvl-ownership.migrations.enabled' => false]);
    }
}
