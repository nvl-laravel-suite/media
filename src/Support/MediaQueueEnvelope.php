<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;

/** Supplies only a fail-closed or disabled fallback when a producer omits context. */
final class MediaQueueEnvelope
{
    public static function fallback(?TenantJobEnvelope $envelope): TenantJobEnvelope
    {
        if ($envelope instanceof TenantJobEnvelope) {
            return $envelope;
        }

        return new TenantJobEnvelope(new TenantContextSnapshot(
            config('nvl-tenancy.enabled') === true
                ? TenantContextMode::Unresolved
                : TenantContextMode::Disabled,
        ));
    }

    /** Capture scalar state inside native event/job constructors. */
    public static function ownership(): OwnershipJobEnvelope
    {
        $context = app(OwnershipContext::class);
        if ($context->snapshot()->mode === OwnershipContextMode::Unresolved) {
            foreach (app(OwnershipResourceRegistry::class)->all() as $resource) {
                if ($resource->ownership->strategy !== 'unscoped') {
                    return OwnershipJobEnvelope::capture($context);
                }
            }

            return OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled));
        }

        return OwnershipJobEnvelope::capture($context);
    }

    /** Validate native uniqueness against the captured authority before acquiring its lock. */
    public static function key(OwnershipJobEnvelope $envelope, string $identity): string
    {
        return app(OwnershipQueueContext::class)->run($envelope, static fn (): string => $envelope->key($identity));
    }

    private function __construct() {}
}
