<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Data\MediaCatalogSnapshot;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/** Narrow authorized scalar reader for one granted platform asset. */
final readonly class MediaCatalogReader
{
    public function __construct(private TenantContext $context) {}

    public function find(string $grantId): MediaCatalogSnapshot
    {
        $tenant = $this->context->requireTenant();
        $grant = MediaTenantGrant::query()->whereKey($grantId)->first();
        if (! $grant instanceof MediaTenantGrant || $grant->tenant_id !== $tenant->value
            || ! $grant->enabled || $grant->revoked_at !== null) {
            throw new TenantBoundaryViolation('The Media catalog grant is unavailable.');
        }

        $source = Media::withoutGlobalScope('tenant')
            ->select(['id', 'revision', 'digest', 'disk', 'storage_path', 'filename', 'extension', 'mime_type', 'size', 'metadata', 'tags', 'status'])
            ->whereKey($grant->media_id)
            ->whereNull('tenant_id')
            ->where('ownership_key', 'platform')
            ->first();
        if (! $source instanceof Media || $source->revision !== $grant->source_revision
            || $source->status !== MediaLifecycleStatus::Available || ! is_string($source->storage_path)) {
            throw new TenantBoundaryViolation('The granted platform Media revision is unavailable.');
        }

        $translations = [];
        foreach (MediaTranslation::withoutGlobalScope('tenant')
            ->where('media_id', $source->id)
            ->where('ownership_key', 'platform')
            ->orderBy('locale')
            ->get(['locale', 'title', 'alt', 'caption', 'description']) as $translation) {
            $translations[$translation->locale] = [
                'title' => $translation->title,
                'alt' => $translation->alt,
                'caption' => $translation->caption,
                'description' => $translation->description,
            ];
        }

        return new MediaCatalogSnapshot(
            grantId: $grant->id,
            grantRevision: $grant->revision,
            sourceId: $source->id,
            sourceRevision: $source->revision,
            sourceDigest: $source->digest,
            disk: $source->disk,
            storagePath: $source->storage_path,
            filename: $source->filename,
            extension: $source->extension,
            mimeType: $source->mime_type,
            size: $source->size,
            metadata: is_array($source->metadata) ? $source->metadata : [],
            tags: is_array($source->tags) ? $source->tags : [],
            translations: $translations,
        );
    }
}
