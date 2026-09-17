<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

/** Boots the mixed platform/tenant Media catalog profile. */
abstract class MediaCatalogTenancyTestCase extends MediaTenancyTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('tenancy.sharing.media', 'copy');
    }
}
