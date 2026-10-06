<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Nvl\Media\Contracts\RevokeMediaTenantGrantContract;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Events\MediaCatalogGrantAudited;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Revokes future imports without affecting committed tenant copies.
 *
 * @api
 */
final readonly class RevokeMediaTenantGrantAction implements RevokeMediaTenantGrantContract
{
    public function __construct(private TenantContext $context, private DomainEventDispatcher $domainEvents) {}

    public function execute(string $grantId, int $expectedRevision): MediaTenantGrant
    {
        if ($this->context->snapshot()->mode !== TenantContextMode::Platform) {
            throw new TenantBoundaryViolation('Media grant revocation requires platform context.');
        }

        return (new Media)->getConnection()->transaction(function () use ($grantId, $expectedRevision): MediaTenantGrant {
            $identity = MediaTenantGrant::withoutGlobalScope('tenant')->whereKey($grantId)->first(['tenant_id', 'media_id']);
            if (! $identity instanceof MediaTenantGrant) {
                throw new TenantBoundaryViolation('The Media grant revision is unavailable.');
            }
            (new Media)->getConnection()->table(MediaTables::get(MediaTables::TenantGrantLocks))->insertOrIgnore([
                'tenant_id' => $identity->tenant_id,
                'media_id' => $identity->media_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            (new Media)->getConnection()->table(MediaTables::get(MediaTables::TenantGrantLocks))
                ->where('tenant_id', $identity->tenant_id)
                ->where('media_id', $identity->media_id)
                ->lockForUpdate()
                ->first();
            $grant = MediaTenantGrant::withoutGlobalScope('tenant')->whereKey($grantId)->lockForUpdate()->first();
            if (! $grant instanceof MediaTenantGrant || $grant->revision !== $expectedRevision || ! $grant->enabled) {
                throw new TenantBoundaryViolation('The Media grant revision is unavailable.');
            }
            $grant->forceFill([
                'enabled' => false,
                'revoked_at' => now(),
                'revision' => $grant->revision + 1,
            ])->save();
            $this->domainEvents->dispatch(new MediaCatalogGrantAudited(
                'revoked', $grant->id, $grant->tenant_id, $grant->media_id, $grant->source_revision, $grant->revision,
            ), $grant->getConnection());

            return $grant->refresh();
        });
    }
}
