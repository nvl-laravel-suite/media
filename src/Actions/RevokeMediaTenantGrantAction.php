<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/** Revokes future imports without affecting committed tenant copies. */
final readonly class RevokeMediaTenantGrantAction
{
    public function __construct(private TenantContext $context) {}

    public function execute(string $grantId, int $expectedRevision): MediaTenantGrant
    {
        if ($this->context->snapshot()->mode !== TenantContextMode::Platform) {
            throw new TenantBoundaryViolation('Media grant revocation requires platform context.');
        }

        return DB::transaction(function () use ($grantId, $expectedRevision): MediaTenantGrant {
            $grant = MediaTenantGrant::withoutGlobalScope('tenant')->whereKey($grantId)->lockForUpdate()->first();
            if (! $grant instanceof MediaTenantGrant || $grant->revision !== $expectedRevision || ! $grant->enabled) {
                throw new TenantBoundaryViolation('The Media grant revision is unavailable.');
            }
            $grant->forceFill([
                'enabled' => false,
                'revoked_at' => now(),
                'revision' => $grant->revision + 1,
            ])->save();

            return $grant->refresh();
        });
    }
}
