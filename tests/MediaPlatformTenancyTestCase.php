<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

/** Boots Media's explicitly platform-owned tenancy profile. */
abstract class MediaPlatformTenancyTestCase extends MediaTenancyTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nvl-tenancy.resources.media', 'platform');
        $app['config']->set('nvl-media.tenancy.owner_types', []);
    }
}
