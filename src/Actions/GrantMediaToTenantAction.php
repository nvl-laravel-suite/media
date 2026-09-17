<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Grants one exact platform Media revision to an active recipient tenant. */
final readonly class GrantMediaToTenantAction
{
    public function __construct(private TenantContext $context, private TenantDirectory $directory) {}

    public function execute(string $mediaId, TenantId $recipient, int $sourceRevision): MediaTenantGrant
    {
        if ($this->context->snapshot()->mode !== TenantContextMode::Platform
            || $this->directory->find($recipient)->status !== TenantStatus::Active) {
            throw new TenantBoundaryViolation('Media grants require platform context and an active recipient.');
        }

        return DB::transaction(function () use ($mediaId, $recipient, $sourceRevision): MediaTenantGrant {
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

                return $existing->refresh();
            }

            return MediaTenantGrant::withoutGlobalScope('tenant')->forceCreate([
                'id' => (string) Str::uuid(),
                'tenant_id' => $recipient->value,
                'media_id' => $source->id,
                'source_revision' => $sourceRevision,
                'revision' => 1,
                'enabled' => true,
            ]);
        });
    }
}
