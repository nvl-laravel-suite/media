<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Config\Repository;
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
    public function __construct(private TenantContext $context, private Repository $configuration) {}

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
            metadata: $this->metadataProjection($source->metadata),
            tags: $this->tagProjection($source->tags),
            translations: $translations,
        );
    }

    /** @return array<string, bool|float|int|string|null> */
    private function metadataProjection(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $configuredKeys = $this->configuration->get('media.catalog.metadata_keys', []);
        $approved = [];
        if (is_array($configuredKeys)) {
            foreach ($configuredKeys as $key) {
                if (is_string($key)) {
                    $approved[$key] = true;
                }
            }
        }

        return array_filter(
            $metadata,
            static fn (mixed $value, mixed $key): bool => is_string($key)
                && isset($approved[$key])
                && (is_scalar($value) || $value === null)
                && (! is_string($value) || mb_strlen($value) <= 1_000),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return list<string> */
    private function tagProjection(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $configuredMaximum = $this->configuration->get('media.catalog.max_tags', 25);
        $configuredLength = $this->configuration->get('media.catalog.max_tag_length', 100);
        $maximum = max(0, is_int($configuredMaximum) ? $configuredMaximum : 25);
        $length = max(1, is_int($configuredLength) ? $configuredLength : 100);

        return array_slice(array_values(array_filter(
            $tags,
            static fn (mixed $tag): bool => is_string($tag) && $tag !== '' && mb_strlen($tag) <= $length,
        )), 0, $maximum);
    }
}
