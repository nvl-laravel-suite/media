<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

/** Boots tenant-aware Media asset routes before providers register them. */
abstract class MediaTenantAssetTestCase extends MediaTenancyTestCase
{
    /** Configure the tenant asset route surface. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media.routes.assets_enabled', true);
    }
}
