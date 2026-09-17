<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Tests\Fixtures\MediaTenancyDirectory;
use Nvl\Media\Tests\Fixtures\MediaTenancyFixtureServiceProvider;
use Nvl\Media\Tests\Fixtures\MediaTenancyMaintenanceMode;
use Nvl\Media\Tests\Fixtures\MediaTenancyPlatformAccess;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;

/** Boots Media's non-transactional tenancy adoption fixture. */
abstract class MediaTenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            FilterableServiceProvider::class,
            SupportServiceProvider::class,
            TenancyServiceProvider::class,
            MediaTenancyFixtureServiceProvider::class,
            TranslatableServiceProvider::class,
            MediaServiceProvider::class,
        ];
    }

    /** Configure tenancy structurally before package providers boot. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'cache.default' => 'array',
            'filesystems.default' => 'tenant-disk',
            'filesystems.disks.tenant-disk' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/tenant-media')],
            'media.disk' => 'tenant-disk',
            'media.routes.api_enabled' => false,
            'media.routes.assets_enabled' => false,
            'media.default_path' => 'owners/{model_id}',
            'media.file_types.txt' => 'text/plain',
            'media.group_types.document' => ['txt'],
            'media.migrations.enabled' => true,
            'media.tenancy.owner_types' => [TestMediaModel::class],
            'tenancy.enabled' => true,
            'tenancy.resources.media' => 'tenant',
            'tenancy.sharing.media' => 'none',
            'tenancy.directory.driver' => 'host',
            'tenancy.directory.adapter' => null,
            'tenancy.access.platform' => null,
        ]);
        $app->instance(MaintenanceMode::class, new MediaTenancyMaintenanceMode);
        $app->instance(TenantDirectory::class, new MediaTenancyDirectory);
        $app->instance(PlatformAccess::class, new MediaTenancyPlatformAccess);
    }

    /** Load only the opt-in core schema after Testbench refreshes normal Media migrations. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }
}
