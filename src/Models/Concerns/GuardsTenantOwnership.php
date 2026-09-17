<?php

declare(strict_types=1);

namespace Nvl\Media\Models\Concerns;

use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/** Prevents persisted tenant partition identity from changing through model writes. */
trait GuardsTenantOwnership
{
    /** Register immutable ownership enforcement for updates. */
    protected static function bootGuardsTenantOwnership(): void
    {
        static::updating(static function (self $model): void {
            foreach (['tenant_id', 'ownership_key'] as $attribute) {
                if (array_key_exists($attribute, $model->getAttributes()) && $model->isDirty($attribute)) {
                    throw new TenantBoundaryViolation('Persisted Media ownership is immutable.');
                }
            }
        });
    }
}
