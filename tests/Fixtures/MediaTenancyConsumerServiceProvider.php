<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers Media's standalone worker fixture without consumer-package test dependencies. */
final class MediaTenancyConsumerServiceProvider extends ServiceProvider
{
    /** Bind host-owned fixture ports. */
    public function register(): void
    {
        $this->app->singleton(TenantDirectory::class, MediaTenancyDirectory::class);
        $this->app->singleton(PlatformAccess::class, MediaTenancyPlatformAccess::class);
        $this->app->singleton(MaintenanceMode::class, MediaTenancyConsumerMaintenanceMode::class);
        $this->app->singleton(TenancyOwnerAdoptionAdapter::class);
    }

    /** Register the owner graph, setup command, and a clean-scope worker probe. */
    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition('test.media-owners', 'test.media-owners', TestMediaModel::class));
        $adoptions->register('resource-fixture-owners', TenancyOwnerAdoptionAdapter::class);
        $this->commands([MediaTenancyConsumerSetupCommand::class]);

        Queue::looping(static function (Looping $event): void {
            if (! Schema::hasTable('media_tenant_worker_probes')) {
                return;
            }
            DB::table('media_tenant_worker_probes')->updateOrInsert(
                ['probe_key' => 'worker-scope'],
                ['tenant_id' => app(TenantContext::class)->snapshot()->tenantId?->value],
            );
        });
    }
}
