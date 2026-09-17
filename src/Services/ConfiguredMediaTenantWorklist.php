<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\MediaTenantWorklist;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;

/** Conservative default worklist populated only by reviewed host configuration. */
final readonly class ConfiguredMediaTenantWorklist implements MediaTenantWorklist
{
    public function __construct(private Repository $configuration) {}

    public function activeTenantIds(): array
    {
        $configured = (array) $this->configuration->get('media.tenancy.active_tenant_worklist', []);
        if (array_any($configured, static fn (mixed $id): bool => ! is_string($id) || ! Str::isUuid($id))) {
            throw new TenantConfigurationInvalid('The Media active tenant worklist must contain canonical UUIDs.');
        }
        /** @var list<string> $ids */
        $ids = array_values(array_unique($configured));

        sort($ids);

        return $ids;
    }
}
