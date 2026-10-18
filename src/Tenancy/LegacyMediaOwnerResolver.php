<?php

declare(strict_types=1);

namespace Nvl\Media\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Throwable;

/** Reloads Media owners through their registered domain ownership boundary. */
final readonly class LegacyMediaOwnerResolver
{
    public function __construct(
        private TenantResourceRegistry $resources,
        private TenantBoundary $boundary,
        private EffectiveTenantConnection $connections,
        private Repository $configuration,
    ) {}

    /** Return canonical persisted owner data for the active tenant. */
    public function resolve(Model $owner, bool $lock = false): Model
    {
        $key = $owner->getKey();
        if ($this->configuration->get('nvl-tenancy.enabled') !== true) {
            return $owner;
        }
        if (! is_int($key) && ! is_string($key)) {
            throw new TenantBoundaryViolation('A canonical persisted Media owner is required.');
        }

        try {
            $definition = $this->resources->forModel($owner);
            $query = $this->boundary->query((new $definition->model)->newQuery(), $definition->key)
                ->whereKey($key);
            if ($lock) {
                $query->lockForUpdate();
            }
            $canonical = $query->first();
        } catch (Throwable) {
            throw new TenantBoundaryViolation('The Media owner is not registered for tenant ownership.');
        }

        if (! $canonical instanceof Model
            || $canonical->getConnection() !== $this->connections->core()) {
            throw new TenantBoundaryViolation('The Media owner is unavailable in the active tenant.');
        }

        return $canonical;
    }
}
