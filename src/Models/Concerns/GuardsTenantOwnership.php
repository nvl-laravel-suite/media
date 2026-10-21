<?php

declare(strict_types=1);

namespace Nvl\Media\Models\Concerns;

use Nvl\Support\Ownership\Compatibility\LegacyModelOwnership;

/** Prevents persisted tenant partition identity from changing through model writes. */
trait GuardsTenantOwnership
{
    /** Register immutable ownership enforcement for updates. */
    protected static function bootGuardsTenantOwnership(): void
    {
        static::updating(static function (self $model): void {
            LegacyModelOwnership::assertImmutable($model, ['tenant_id', 'ownership_key'], 'Media');
        });
    }
}
