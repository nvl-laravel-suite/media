<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Str;
use Nvl\Media\Contracts\GrantMediaToTenantContract;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Events\MediaCatalogGrantAudited;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Grants one exact platform Media revision to an active recipient tenant.
 *
 * @api
 */
final readonly class GrantMediaToTenantAction implements GrantMediaToTenantContract
{
    public function __construct(private TenantContext $context, private TenantDirectory $directory, private DomainEventDispatcher $domainEvents) {}

    public function execute(string $mediaId, TenantId $recipient, int $sourceRevision): MediaTenantGrant
    {
        if ($this->context->snapshot()->mode !== TenantContextMode::Platform
            || $this->directory->find($recipient)->status !== TenantStatus::Active) {
            throw new TenantBoundaryViolation('Media grants require platform context and an active recipient.');
        }

        return (new Media)->getConnection()->transaction(function () use ($mediaId, $recipient, $sourceRevision): MediaTenantGrant {
            (new Media)->getConnection()->table(MediaTables::get(MediaTables::TenantGrantLocks))->insertOrIgnore([
                'tenant_id' => $recipient->value,
                'media_id' => $mediaId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            (new Media)->getConnection()->table(MediaTables::get(MediaTables::TenantGrantLocks))
                ->where('tenant_id', $recipient->value)
                ->where('media_id', $mediaId)
                ->lockForUpdate()
                ->first();

            $source = Media::withoutGlobalScope('tenant')
                ->whereKey($mediaId)
                ->whereNull('tenant_id')
                ->where('ownership_key', 'platform')
                ->lockForUpdate()
                ->first();
            if (! $source instanceof Media || $source->revision !== $sourceRevision
                || $source->status !== MediaLifecycleStatus::Available) {
                throw new TenantBoundaryViolation('The platform Media source revision is unavailable.');
            }

            $existing = MediaTenantGrant::withoutGlobalScope('tenant')
                ->where('tenant_id', $recipient->value)
                ->where('media_id', $source->id)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof MediaTenantGrant) {
                $existing->forceFill([
                    'source_revision' => $sourceRevision,
                    'revision' => $existing->revision + 1,
                    'enabled' => true,
                    'revoked_at' => null,
                ])->save();

                $this->domainEvents->dispatch(new MediaCatalogGrantAudited(
                    'refreshed', $existing->id, $recipient->value, $source->id, $sourceRevision, $existing->revision,
                ), $existing->getConnection());

                return $existing->refresh();
            }

            $grant = MediaTenantGrant::withoutGlobalScope('tenant')->forceCreate([
                'id' => (string) Str::uuid(),
                'tenant_id' => $recipient->value,
                'media_id' => $source->id,
                'source_revision' => $sourceRevision,
                'revision' => 1,
                'enabled' => true,
            ]);
            $this->domainEvents->dispatch(new MediaCatalogGrantAudited(
                'created', $grant->id, $recipient->value, $source->id, $sourceRevision, $grant->revision,
            ), $grant->getConnection());

            return $grant;
        });
    }
}
