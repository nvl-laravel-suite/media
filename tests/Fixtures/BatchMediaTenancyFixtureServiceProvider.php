<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Media\Tests\Stubs\BatchMediaOwner;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

/** Registers the canonical slot-declaring owner in the real adopted tenant graph. */
final class BatchMediaTenancyFixtureServiceProvider extends ServiceProvider
{
    /** Enable structural tenancy before package runtime hooks. */
    public function register(): void
    {
        $this->app->make('config')->set('nvl-tenancy.enabled', true);
    }

    /** Register the existing fixture schema's exact canonical owner class. */
    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition('test.media-owners', 'test.media-owners', BatchMediaOwner::class));
        $adoptions->register('resource-fixture-owners', TenancyOwnerAdoptionAdapter::class);
    }
}
