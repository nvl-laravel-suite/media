<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\MediaCatalogAccess;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Data\MediaCatalogSnapshot;
use Nvl\Media\Data\StagedCatalogMedia;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaOwnerGrant;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Services\MediaCatalogProjection;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaVariationDispatcher;
use Nvl\Media\Support\MediaHashGenerator;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipScope;

/**
 * Issues recipient-owned capabilities and copies exact central revisions into independent assets.
 *
 * @api
 */
final class MediaOwnerCatalog implements MediaCatalogImport
{
    /** @var array<string, array{file: StagedCatalogMedia, source: MediaCatalogSnapshot, partition: string, persisted: bool}> */
    private array $staged = [];

    public function __construct(
        private readonly OwnershipBoundary $boundary,
        private readonly OwnershipContext $context,
        private readonly OwnershipReadiness $readiness,
        private readonly OwnershipResourceRegistry $resources,
        private readonly MediaCatalogAccess $access,
        private readonly MediaCatalogProjection $projection,
        private readonly MediaFileOperator $files,
        private readonly MediaDiskGateway $disks,
        private readonly MediaPathResolver $paths,
        private readonly MediaFileEffectScheduler $effects,
        private readonly MediaVariationDispatcher $variations,
        private readonly OwnershipWriter $writer,
        private readonly MediaOwnershipConstraints $constraints,
    ) {}

    /** Host policy authorizes issuance for the current admitted recipient, never an ambient tenant ID. */
    public function grant(string $sourceId, int $sourceRevision): MediaOwnerGrant
    {
        $this->admit();
        $this->access->assertCanGrant($sourceId, $sourceRevision);

        return $this->connection()->transaction(function () use ($sourceId, $sourceRevision): MediaOwnerGrant {
            $this->lock($sourceId);
            $grant = MediaOwnerGrant::query()->where('media_id', $sourceId)->lockForUpdate()->first();
            $source = $this->source($sourceId, true);
            if ($source->revision !== $sourceRevision) {
                throw new OwnershipViolation('The central source revision changed.');
            }
            $grant ??= new MediaOwnerGrant;
            $grant->forceFill(['media_id' => $sourceId, 'source_revision' => $sourceRevision, 'revision' => $grant->exists ? $grant->revision + 1 : 1, 'enabled' => true, 'revoked_at' => null])->save();

            return $grant;
        });
    }

    public function revoke(string $grantId, int $expectedRevision): MediaOwnerGrant
    {
        $this->admit();
        $this->access->assertCanRevoke($grantId);

        return $this->connection()->transaction(function () use ($grantId, $expectedRevision): MediaOwnerGrant {
            $grant = MediaOwnerGrant::query()->whereKey($grantId)->lockForUpdate()->firstOrFail();
            if ($grant->revision !== $expectedRevision) {
                throw new OwnershipViolation('The catalog grant revision changed.');
            }
            $grant->forceFill(['enabled' => false, 'revoked_at' => now(), 'revision' => $grant->revision + 1])->save();

            return $grant;
        });
    }

    /** Project only one current granted central source, with no general scope-free reader. */
    public function inspect(string $grantId): MediaCatalogSnapshot
    {
        $this->admit();

        return $this->snapshot($grantId, false);
    }

    public function stage(MediaCatalogSnapshot $source): StagedCatalogMedia
    {
        $this->admit();
        if ($this->inspect($source->grantId) != $source) {
            throw new OwnershipViolation('The catalog snapshot is stale or forged.');
        }
        $path = $this->paths->storageFolder('catalog', $source->disk).'/'.MediaHashGenerator::generateForExtension($source->extension);
        try {
            if (! $this->files->copy($source->disk, $source->storagePath, $source->disk, $path, MediaVisibility::Private)
                || $this->disks->size($source->disk, $path) !== $source->size
                || ! hash_equals($source->sourceDigest, $this->disks->checksum($source->disk, $path))) {
                throw new OwnershipViolation('The granted source could not be copied with verified integrity.');
            }
        } catch (\Throwable $failure) {
            $this->files->delete($source->disk, $path);
            throw $failure;
        }
        $scope = $this->context->snapshot()->owner?->scopeKey() ?? throw new OwnershipViolation;
        $file = new StagedCatalogMedia((string) Str::uuid(), '', $source->disk, $path, $source->sourceDigest, $source->size, $scope);
        $this->staged[$file->operationId] = ['file' => $file, 'source' => $source, 'partition' => $this->boundary->key('media.assets', 'catalog-copy'), 'persisted' => false];

        return $file;
    }

    /** Join the caller's actual source transaction and recheck revocation/revision under locks. */
    public function persist(MediaCatalogSnapshot $source, StagedCatalogMedia $file, string $idempotencyKey): Media
    {
        $this->admit();
        $pending = $this->staged[$file->operationId] ?? null;
        if ($this->connection()->transactionLevel() < 1 || ! Str::isUuid($idempotencyKey)
            || $pending === null || $pending['file'] !== $file || $pending['source'] != $source
            || $pending['partition'] !== $this->boundary->key('media.assets', 'catalog-copy')) {
            throw new OwnershipViolation('A catalog copy requires its original staged tuple and actual source transaction.');
        }
        $this->lock($source->sourceId);
        if ($this->snapshot($source->grantId, true) != $source
            || $this->disks->size($file->disk, $file->storagePath) !== $file->size
            || ! hash_equals($file->digest, $this->disks->checksum($file->disk, $file->storagePath))) {
            throw new OwnershipViolation('The staged copy, source revision or grant changed.');
        }
        $existing = MediaModelRegistry::query()->where('catalog_import_key', $idempotencyKey)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->catalog_source_id !== $source->sourceId || $existing->catalog_source_revision !== $source->sourceRevision || $existing->catalog_source_digest !== $source->sourceDigest) {
                throw new OwnershipViolation('The import identity belongs to another source revision.');
            }
            $this->discard($file);

            return $existing;
        }
        $this->effects->deleteAfterRollback($file->disk, [$file->storagePath], 'owner_catalog_copy');
        $media = MediaModelRegistry::create([
            'filename' => $source->filename, 'hash' => basename($file->storagePath), 'extension' => $source->extension,
            'mime_type' => $source->mimeType, 'size' => $file->size, 'disk' => $file->disk, 'folder' => 'catalog',
            'visibility' => MediaVisibility::Private, 'status' => MediaLifecycleStatus::Available, 'available_at' => now(),
            'type' => MediaType::fromExtension($source->extension), 'digest' => $file->digest, 'tags' => $source->tags, 'metadata' => $source->metadata,
            'storage_path' => $file->storagePath, 'catalog_import_key' => $idempotencyKey, 'catalog_source_id' => $source->sourceId,
            'catalog_source_revision' => $source->sourceRevision, 'catalog_source_digest' => $source->sourceDigest,
        ]);
        foreach ($source->translations as $locale => $attributes) {
            $this->writer->create(MediaTranslation::class, static function (array $ownership) use ($media, $locale, $attributes): MediaTranslation {
                $translation = new MediaTranslation;
                $translation->forceFill([...$attributes, ...$ownership, 'media_id' => $media->id, 'locale' => $locale])->save();

                return $translation;
            });
        }
        $this->staged[$file->operationId]['persisted'] = true;
        $this->effects->afterCommit(function () use ($file): void {
            unset($this->staged[$file->operationId]);
        });
        $this->variations->dispatchConfiguredForUpload($media);

        return $media;
    }

    public function discard(StagedCatalogMedia $file): void
    {
        $pending = $this->staged[$file->operationId] ?? null;
        if ($pending === null || $pending['persisted'] || $pending['file'] !== $file || $pending['partition'] !== $this->boundary->key('media.assets', 'catalog-copy')) {
            return;
        }
        $this->files->delete($file->disk, $file->storagePath);
        unset($this->staged[$file->operationId]);
    }

    private function snapshot(string $grantId, bool $lock): MediaCatalogSnapshot
    {
        $query = MediaOwnerGrant::query()->whereKey($grantId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $grant = $query->first() ?? throw new OwnershipViolation('The catalog grant is unavailable.');
        if (! $grant->enabled || $grant->revoked_at !== null) {
            throw new OwnershipViolation('The catalog grant was revoked.');
        }
        $source = $this->source($grant->media_id, $lock);
        if ($source->revision !== $grant->source_revision || ! is_string($source->storage_path)) {
            throw new OwnershipViolation('The granted central revision is unavailable.');
        }
        $translations = [];
        foreach (MediaTranslation::query()->withoutGlobalScope(OwnershipScope::class)->useWritePdo()->where('media_id', $source->id)->where('owner_scope_key', 'central')->orderBy('locale')->get() as $row) {
            $translations[$row->locale] = ['title' => $row->title, 'alt' => $row->alt, 'caption' => $row->caption, 'description' => $row->description];
        }

        return new MediaCatalogSnapshot($grant->id, $grant->revision, $source->id, $source->revision, $source->digest, $source->disk, $source->storage_path, $source->filename, $source->extension, $source->mime_type, $source->size, $this->projection->metadata($source->metadata), $this->projection->tags($source->tags), $translations);
    }

    private function source(string $id, bool $lock): Media
    {
        $this->readiness->assertActive('media.assets');
        $ownership = $this->resources->get('media.assets')->ownership;
        $query = MediaModelRegistry::query()->withoutGlobalScope(OwnershipScope::class)->useWritePdo()->whereKey($id)->where('owner_scope_key', 'central');
        if ($ownership->strategy === 'column') {
            $query->whereNull($ownership->foreignKey ?? throw new OwnershipViolation);
        } elseif ($ownership->strategy === 'pivot') {
            $model = $query->getModel();
            $query->whereNotExists(fn (Builder $pivot) => $pivot->selectRaw('1')->from($ownership->table ?? throw new OwnershipViolation)->whereColumn($ownership->resourceKey ?? throw new OwnershipViolation, $model->getQualifiedKeyName()));
        } else {
            throw new OwnershipViolation('Central Media catalogs require a concrete primary owner strategy.');
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $source = $query->first() ?? throw new OwnershipViolation('The central source is unavailable.');
        if (! $source->isAvailable()) {
            throw new OwnershipViolation('The central source is unavailable.');
        }

        return $source;
    }

    private function admit(): void
    {
        if ($this->context->snapshot()->mode !== OwnershipContextMode::Owner || ! $this->resources->get('media.assets')->allowsCentral) {
            throw new OwnershipViolation('Owner catalog copies require an explicitly central-capable Media consumer and admitted recipient.');
        }
        $this->boundary->attributes('media.catalog-grants');
        $constraints = $this->constraints;
        $constraints->assertUnique($this->connection(), MediaModelRegistry::newInstance()->getTable(), ['owner_scope_key', 'catalog_import_key']);
        $constraints->assertUnique($this->connection(), (new MediaOwnerGrant)->getTable(), ['owner_scope_key', 'media_id']);
        $constraints->assertUnique($this->connection(), MediaTables::ownerGrantLocks(), ['owner_scope_key', 'media_id']);
    }

    private function lock(string $sourceId): void
    {
        $scope = $this->context->snapshot()->owner?->scopeKey() ?? throw new OwnershipViolation;
        $connection = $this->connection();
        $connection->table(MediaTables::ownerGrantLocks())->insertOrIgnore(['owner_scope_key' => $scope, 'media_id' => $sourceId]);
        $connection->table(MediaTables::ownerGrantLocks())->where('owner_scope_key', $scope)->where('media_id', $sourceId)->lockForUpdate()->first();
    }

    private function connection(): Connection
    {
        return MediaModelRegistry::newInstance()->getConnection();
    }
}
