<?php

declare(strict_types=1);

namespace Nvl\Media\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Tenancy\Services\TenantBoundary;

/** Applies the model's registered ownership policy to ordinary Eloquent reads. */
trait AppliesTenantBoundary
{
    /** Register the ownership boundary as a composable global scope. */
    public static function bootAppliesTenantBoundary(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            app(TenantBoundary::class)->query($query, static::tenantResourceKey());
        });
    }

    /** Return the immutable resource key for this concrete model. */
    abstract protected static function tenantResourceKey(): string;
}
