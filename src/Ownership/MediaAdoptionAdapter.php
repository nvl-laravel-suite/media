<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Contracts\OwnershipAdoptionMetadataValidator;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipResourceBackfill;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use stdClass;

/** Adopts exact reviewed Media records without guessing primary owners from associations. @internal */
final readonly class MediaAdoptionAdapter implements OwnershipAdoptionAdapter, OwnershipAdoptionMetadataValidator
{
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipAdoptionStore $mappings, private OwnershipSchema $schema, private MediaAdoptionVerifier $verification) {}

    /** Require reviewed binary integrity instead of trusting historical database digests. */
    public function validateAssignment(OwnershipMapping $mapping): void
    {
        $binary = in_array($mapping->resource, ['media.assets', 'media.variations'], true);
        if (! $binary && $mapping->metadata !== []) {
            throw new OwnershipConfigurationInvalid('Only Media binary mappings accept expected_digest.');
        }
        if ($binary && (array_keys($mapping->metadata) !== ['expected_digest']
            || ! is_string($mapping->metadata['expected_digest'])
            || preg_match('/^[a-f0-9]{64}$/D', $mapping->metadata['expected_digest']) !== 1)) {
            throw new OwnershipConfigurationInvalid('Media binary mappings require one reviewed SHA-256 expected_digest.');
        }
    }

    /** @return list<string> */
    public function resources(): array
    {
        return ['media.assets', 'media.associations', 'media.variations', 'media.translations', 'media.multipart', 'media.slot-operations', 'media.catalog-grants'];
    }

    /** Prepare additive partitions and replace only the package's known global receipt identity. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $asset = new ($this->resources->get('media.assets')->model);
        $assetSchema = $asset->getConnection()->getSchemaBuilder();
        foreach (['storage_path', 'catalog_import_key', 'catalog_source_id', 'catalog_source_revision', 'catalog_source_digest'] as $column) {
            if (! $assetSchema->hasColumn($asset->getTable(), $column)) {
                $assetSchema->table($asset->getTable(), static function (Blueprint $table) use ($column): void {
                    if ($column === 'catalog_source_revision') {
                        $table->unsignedBigInteger($column)->nullable();
                    } elseif (in_array($column, ['catalog_import_key', 'catalog_source_id'], true)) {
                        $table->uuid($column)->nullable();
                    } else {
                        $table->string($column, $column === 'storage_path' ? 1024 : 64)->nullable();
                    }
                });
            }
        }
        $lockSchema = $asset->getConnection()->getSchemaBuilder();
        if (! $lockSchema->hasTable(MediaTables::ownerGrantLocks())) {
            $lockSchema->create(MediaTables::ownerGrantLocks(), static function (Blueprint $table): void {
                $table->string('owner_scope_key', 191);
                $table->uuid('media_id');
                $table->primary(['owner_scope_key', 'media_id'], 'media_owner_catalog_lock_primary');
            });
        }
        $grant = new MediaTenantGrant;
        $schema = $grant->getConnection()->getSchemaBuilder();
        if (! $schema->hasTable($grant->getTable())) {
            $schema->create($grant->getTable(), static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('media_id');
                $table->unsignedBigInteger('source_revision');
                $table->unsignedBigInteger('revision')->default(1);
                $table->boolean('enabled')->default(true);
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }
        foreach ($this->resources() as $key) {
            $model = new ($this->resources->get($key)->model);
            $schema = $model->getConnection()->getSchemaBuilder();
            if ($schema->hasColumn($model->getTable(), 'tenant_id')) {
                $schema->table($model->getTable(), static fn (Blueprint $table) => $table->uuid('tenant_id')->nullable()->change());
            }
            if ($schema->hasColumn($model->getTable(), 'ownership_key')) {
                $schema->table($model->getTable(), static fn (Blueprint $table) => $table->string('ownership_key', 44)->nullable()->change());
            }
        }
        foreach ($this->resources() as $key) {
            $this->schema->prepare($key);
        }
        $this->unique($asset, ['owner_scope_key', 'catalog_import_key'], 'media_owner_catalog_import_unique');
        $this->unique($grant, ['owner_scope_key', 'media_id'], 'media_owner_grants_identity_unique');
        $definition = $this->resources->get('media.slot-operations');
        $model = new $definition->model;
        foreach ($model->getConnection()->getSchemaBuilder()->getIndexes($model->getTable()) as $index) {
            if ($index['unique'] && $index['columns'] === ['owner_scope_key', 'idempotency_key']) {
                return;
            }
            if ($index['unique'] && in_array($index['columns'], [['idempotency_key'], ['tenant_id', 'idempotency_key']], true)) {
                $model->getConnection()->getSchemaBuilder()->table($model->getTable(), static fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }
        $model->getConnection()->getSchemaBuilder()->table($model->getTable(), static fn (Blueprint $table) => $table->unique(['owner_scope_key', 'idempotency_key'], 'media_owner_scope_idempotency_unique'));
    }

    /** Backfill bounded actual records from explicit immutable per-record mappings. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        return (new OwnershipResourceBackfill($this->resources, $this->mappings))->run($plan, $this->resources(), $cursor, $limit, 'Media', static function (string $key, Model $model, stdClass $row, OwnershipMapping $mapping): array {
            $owner = $mapping->owner;
            $attributes = [];
            if ($key === 'media.assets' && (! isset($row->storage_path) || $row->storage_path === '')) {
                if (! is_string($row->disk) || ! is_string($row->hash) || ($row->folder !== null && ! is_string($row->folder))) {
                    throw new OwnershipConfigurationInvalid('Historical Media paths require canonical source facts.');
                }
                $attributes['storage_path'] = MediaPathResolver::storagePath($row->folder ?? '', $row->disk).'/'.$row->hash;
                $attributes['storage_path'] = ltrim($attributes['storage_path'], '/');
            }

            return $attributes;
        });
    }

    /** Install only a known package-owned natural identity.
     * @param  list<string>  $columns
     */
    private function unique(Model $model, array $columns, string $name): void
    {
        foreach ($model->getConnection()->getSchemaBuilder()->getIndexes($model->getTable()) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return;
            }
        }
        $model->getConnection()->getSchemaBuilder()->table($model->getTable(), static fn (Blueprint $table) => $table->unique($columns, $name));
    }

    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        foreach ($this->resources() as $key) {
            $this->schema->verify($key);
        }

        $this->verification->verify($plan);

        return new OwnershipVerification([]);
    }

    public function activate(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $key) {
            $this->schema->constrain($key);
        }
    }
}
