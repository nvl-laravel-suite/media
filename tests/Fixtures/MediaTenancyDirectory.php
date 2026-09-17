<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Resolves the two active Media fixture tenants. */
final readonly class MediaTenancyDirectory implements TenantDirectory
{
    /** Resolve one fixture tenant. */
    public function find(TenantId $tenant): TenantDescriptor
    {
        if (! in_array($tenant->value, [MediaTenancyScenario::A, MediaTenancyScenario::B], true)) {
            throw new TenantNotFound;
        }

        return new TenantDescriptor($tenant, TenantStatus::Active);
    }
}
