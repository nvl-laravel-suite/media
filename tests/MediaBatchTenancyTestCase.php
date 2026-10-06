<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Nvl\Media\Tests\Fixtures\BatchMediaTenancyFixtureServiceProvider;
use Nvl\Media\Tests\Fixtures\MediaTenancyFixtureServiceProvider;
use Nvl\Media\Tests\Stubs\BatchMediaOwner;

/** Real enabled tenancy fixture for attribute-dependent Media slot declarations. */
abstract class MediaBatchTenancyTestCase extends MediaTenancyTestCase
{
    /** Retain the real providers with an exact slot-declaring owner registration.
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_map(static fn (string $provider): string => $provider === MediaTenancyFixtureServiceProvider::class ? BatchMediaTenancyFixtureServiceProvider::class : $provider, parent::getPackageProviders($app));
    }

    /** Configure the existing fixture owner allowlist before activation. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nvl-media.tenancy.owner_types', [BatchMediaOwner::class]);
    }
}
