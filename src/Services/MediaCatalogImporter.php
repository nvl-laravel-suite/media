<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Data\MediaCatalogSnapshot;
use Nvl\Media\Data\StagedCatalogMedia;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Support\MediaHashGenerator;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenantBoundary;

/** Stages and persists independent tenant copies of authorized platform Media. */
final class MediaCatalogImporter implements MediaCatalogImport
{
    /** @var array<string, StagedCatalogMedia> */
    private array $staged = [];

    /** @var array<string, true> */
    private array $persisted = [];

    public function __construct(
        private readonly MediaCatalogReader $reader,
        private readonly MediaFileOperator $files,
        private readonly MediaDiskGateway $disks,
        private readonly MediaPathResolver $paths,
        private readonly MediaFileEffectScheduler $fileEffects,
        private readonly TenantContext $context,
        private readonly TenantBoundary $boundary,
        private readonly EffectiveTenantConnection $connections,
    ) {}

    public function inspect(string $grantId): MediaCatalogSnapshot
    {
        return $this->reader->find($grantId);
    }

    public function stage(MediaCatalogSnapshot $source): StagedCatalogMedia
    {
        $tenant = $this->context->requireTenant();
        $operationId = (string) Str::uuid();
        $hash = MediaHashGenerator::generateForExtension($source->extension);
        $path = $this->paths->storageFolder('catalog').'/'.$hash;
        if (! $this->files->copy($source->disk, $source->storagePath, $source->disk, $path, MediaVisibility::Private)
            || $this->disks->size($source->disk, $path) !== $source->size
            || ! hash_equals($source->sourceDigest, $this->disks->checksum($source->disk, $path))) {
            $this->files->delete($source->disk, $path);
            throw new TenantBoundaryViolation('The granted Media source could not be staged safely.');
        }

        $file = new StagedCatalogMedia($operationId, $tenant->value, $source->disk, $path, $source->sourceDigest, $source->size);
        $this->staged[$operationId] = $file;

        return $file;
    }

    public function persist(MediaCatalogSnapshot $source, StagedCatalogMedia $file, string $idempotencyKey): Media
    {
        $connection = $this->connection();
        $tenant = $this->context->requireTenant();
        if ($connection->transactionLevel() < 1 || ! Str::isUuid($idempotencyKey)
            || $file->tenantId !== $tenant->value || ($this->staged[$file->operationId] ?? null) !== $file
            || $this->disks->size($file->disk, $file->storagePath) !== $file->size
            || ! hash_equals($file->digest, $this->disks->checksum($file->disk, $file->storagePath))) {
            throw new TenantBoundaryViolation('The staged Media tuple is invalid or no transaction is active.');
        }

        $grant = MediaTenantGrant::withoutGlobalScope('tenant')
            ->whereKey($source->grantId)
            ->where('tenant_id', $tenant->value)
            ->lockForUpdate()
            ->first();
        $platform = Media::withoutGlobalScope('tenant')
            ->whereKey($source->sourceId)
            ->whereNull('tenant_id')
            ->where('ownership_key', 'platform')
            ->lockForUpdate()
            ->first();
        if (! $grant instanceof MediaTenantGrant || ! $grant->enabled || $grant->revoked_at !== null
            || $grant->revision !== $source->grantRevision || $grant->source_revision !== $source->sourceRevision
            || ! $platform instanceof Media || $platform->revision !== $source->sourceRevision
            || $platform->status !== MediaLifecycleStatus::Available
            || ! hash_equals($platform->digest, $source->sourceDigest)) {
            throw new TenantBoundaryViolation('The Media grant or platform source changed during staging.');
        }

        $existing = Media::query()->where('catalog_import_key', $idempotencyKey)->lockForUpdate()->first();
        if ($existing instanceof Media) {
            if ($existing->catalog_source_id !== $source->sourceId
                || $existing->catalog_source_revision !== $source->sourceRevision
                || $existing->catalog_source_digest !== $source->sourceDigest) {
                throw new TenantBoundaryViolation('The Media import key belongs to another source revision.');
            }
            $this->files->delete($file->disk, $file->storagePath);
            unset($this->staged[$file->operationId]);

            return $existing;
        }

        $this->fileEffects->deleteAfterRollback($file->disk, [$file->storagePath], 'catalog_import');
        $media = Media::query()->forceCreate([
            ...$this->boundary->attributes('media.assets'),
            'filename' => $source->filename,
            'hash' => basename($file->storagePath),
            'extension' => $source->extension,
            'mime_type' => $source->mimeType,
            'size' => $file->size,
            'disk' => $file->disk,
            'folder' => 'catalog',
            'is_public' => false,
            'visibility' => MediaVisibility::Private,
            'status' => MediaLifecycleStatus::Available,
            'available_at' => now(),
            'type' => MediaType::fromExtension($source->extension),
            'digest' => $file->digest,
            'tags' => $source->tags,
            'metadata' => $source->metadata,
            'storage_path' => $file->storagePath,
            'catalog_import_key' => $idempotencyKey,
            'catalog_source_id' => $source->sourceId,
            'catalog_source_revision' => $source->sourceRevision,
            'catalog_source_digest' => $source->sourceDigest,
        ]);
        foreach ($source->translations as $locale => $translation) {
            MediaTranslation::query()->forceCreate([
                'tenant_id' => $media->tenant_id,
                'ownership_key' => $media->ownership_key,
                'media_id' => $media->id,
                'locale' => $locale,
                'title' => $translation['title'] ?? null,
                'alt' => $translation['alt'] ?? null,
                'caption' => $translation['caption'] ?? null,
                'description' => $translation['description'] ?? null,
            ]);
        }
        $this->persisted[$file->operationId] = true;

        return $media;
    }

    public function discard(StagedCatalogMedia $file): void
    {
        if (($this->staged[$file->operationId] ?? null) !== $file || isset($this->persisted[$file->operationId])) {
            return;
        }
        $this->files->delete($file->disk, $file->storagePath);
        unset($this->staged[$file->operationId]);
    }

    private function connection(): Connection
    {
        $connection = (new Media)->getConnection();
        if ($connection !== $this->connections->core()) {
            throw new TenantBoundaryViolation('Media catalog import requires the canonical tenant connection.');
        }

        return $connection;
    }
}
