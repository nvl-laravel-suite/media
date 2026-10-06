<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

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
            config('tenancy.enabled') === true
                ? TenantContextMode::Unresolved
                : TenantContextMode::Disabled,
        ));
    }

    private function __construct() {}
}
