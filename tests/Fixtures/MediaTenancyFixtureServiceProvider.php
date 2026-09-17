<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers only the test-owned canonical Media owner resource and adapter. */
final class MediaTenancyFixtureServiceProvider extends ServiceProvider
{
    /** Register fixture ownership before the final configuration validation. */
    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition('test.media-owners', 'test.media-owners', TestMediaModel::class));
        $adoptions->register('resource-fixture-owners', TenancyOwnerAdoptionAdapter::class);
    }
}
