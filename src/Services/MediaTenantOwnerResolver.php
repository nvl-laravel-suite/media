<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Throwable;

/** Reloads Media owners through their registered domain ownership boundary. */
final readonly class MediaTenantOwnerResolver
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
        if ($this->configuration->get('tenancy.enabled') !== true) {
            return $owner;
        }
        if (! $owner instanceof HasMedia || (! is_int($key) && ! is_string($key))) {
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

        if (! $canonical instanceof HasMedia
            || $canonical->getConnection() !== $this->connections->core()) {
            throw new TenantBoundaryViolation('The Media owner is unavailable in the active tenant.');
        }

        return $canonical;
    }
}
