<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Support\MediaConfiguration;

return new class extends Migration
{
    /** Apply verified tenant partitions, indexes, and concrete parent constraints. */
    public function up(): void
    {
        $mixed = config('tenancy.sharing.media') === 'copy';
        $platform = config('tenancy.resources.media') === 'platform';
        $schema = Schema::getFacadeRoot();

        $this->required($schema, MediaTables::Media, 'tenant_id', $mixed || $platform);
        if ($mixed) {
            $this->required($schema, MediaTables::Media, 'ownership_key', false, 'string');
            $this->ownershipCheck(DB::connection(), MediaTables::Media);
        }
        $this->unique($schema, MediaTables::Media, [$mixed ? 'ownership_key' : 'tenant_id', 'id'], 'media_partition_id_unique');
        if ($mixed || $platform) {
            $this->unique($schema, MediaTables::Media, ['tenant_id', 'id'], 'media_tenant_id_unique');
        }
        $this->unique($schema, MediaTables::Media, ['tenant_id', 'catalog_import_key'], 'media_tenant_catalog_import_unique');
        $this->index($schema, MediaTables::Media, ['tenant_id', 'digest', 'disk', 'visibility'], 'media_tenant_digest_disk_visibility_idx');
        $this->index($schema, MediaTables::Media, ['tenant_id', 'status', 'created_at'], 'media_tenant_status_created_idx');

        foreach ([MediaTables::Associations, MediaTables::ImageVariations, MediaTables::I18n] as $child) {
            $this->required($schema, $child, 'tenant_id', $mixed || $platform);
            if ($mixed) {
                $this->required($schema, $child, 'ownership_key', false, 'string');
                $this->ownershipCheck(DB::connection(), $child);
            }
            $partition = $mixed ? 'ownership_key' : 'tenant_id';
            $this->foreign($schema, $child, [$partition, 'media_id'], MediaTables::Media, [$partition, 'id'], $child.'_media_partition_foreign');
        }
        $this->index($schema, MediaTables::Associations, ['tenant_id', 'associable_type', 'associable_id', 'collection'], 'media_assoc_tenant_owner_collection_idx');
        $this->unique($schema, MediaTables::I18n, [$mixed ? 'ownership_key' : 'tenant_id', 'media_id', 'locale'], 'media_i18n_partition_locale_unique');

        $this->required($schema, MediaTables::MultipartUploads, 'tenant_id', $platform);
        if ($platform) {
            $this->required($schema, MediaTables::MultipartUploads, 'ownership_key', false, 'string');
            $this->ownershipCheck(DB::connection(), MediaTables::MultipartUploads);
        }
        $this->index($schema, MediaTables::MultipartUploads, ['tenant_id', 'status', 'expires_at'], 'media_multipart_tenant_status_expiry_idx');
        $this->foreign($schema, MediaTables::MultipartUploads, ['tenant_id', 'completed_media_id'], MediaTables::Media, ['tenant_id', 'id'], 'media_multipart_completed_tenant_foreign');

        $operationConnection = DB::connection(MediaConfiguration::ownerSlotOperationConnection());
        $operationSchema = $operationConnection->getSchemaBuilder();
        $operationTable = MediaConfiguration::ownerSlotOperationTable();
        $this->required($operationSchema, $operationTable, 'tenant_id', false);
        if ($operationSchema->hasIndex($operationTable, 'media_owner_slot_idempotency_unique')) {
            $operationSchema->table($operationTable, static fn (Blueprint $table) => $table->dropUnique('media_owner_slot_idempotency_unique'));
        }
        $this->unique($operationSchema, $operationTable, ['tenant_id', 'idempotency_key'], 'media_owner_slot_tenant_idempotency_unique');
        $schema->enableForeignKeyConstraints();
        $operationSchema->enableForeignKeyConstraints();
    }

    /** Remove final constraints while retaining expanded adoption data. */
    public function down(): void
    {
        $this->dropOwnershipCheck(DB::connection(), MediaTables::Media);
        foreach ([MediaTables::Associations, MediaTables::ImageVariations, MediaTables::I18n, MediaTables::MultipartUploads] as $table) {
            $this->dropOwnershipCheck(DB::connection(), $table);
        }
    }

    /** Change one prepared ownership column to its final nullability. */
    private function required(Builder $schema, string $table, string $column, bool $nullable, string $type = 'uuid'): void
    {
        if ($schema->hasTable($table) && $schema->hasColumn($table, $column)) {
            $schema->table($table, static function (Blueprint $blueprint) use ($column, $nullable, $type): void {
                $definition = $type === 'string'
                    ? $blueprint->string($column, 44)
                    : $blueprint->uuid($column);
                $definition->nullable($nullable)->change();
            });
        }
    }

    /** Add one final unique index idempotently. */
    private function unique(Builder $schema, string $table, array $columns, string $name): void
    {
        if (! $schema->hasIndex($table, $name)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
        }
    }

    /** Add one final query index idempotently. */
    private function index(Builder $schema, string $table, array $columns, string $name): void
    {
        if (! $schema->hasIndex($table, $name)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    /** Add one concrete composite parent constraint idempotently. */
    private function foreign(Builder $schema, string $table, array $columns, string $parent, array $parentColumns, string $name): void
    {
        $exists = array_any($schema->getForeignKeys($table), static fn (array $foreign): bool => $foreign['name'] === $name);
        if (! $exists) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->foreign($columns, $name)->references($parentColumns)->on($parent)->cascadeOnDelete());
        }
    }

    /** Enforce the canonical mixed ownership discriminator on every write. */
    private function ownershipCheck(Connection $connection, string $table): void
    {
        $name = $table.'_ownership_check';
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            foreach (['insert', 'update'] as $operation) {
                $trigger = $name.'_'.$operation;
                $connection->unprepared("CREATE TRIGGER IF NOT EXISTS {$trigger} BEFORE ".strtoupper($operation)." ON {$table} BEGIN SELECT CASE WHEN NOT ((NEW.ownership_key = 'platform' AND NEW.tenant_id IS NULL) OR (NEW.tenant_id IS NOT NULL AND NEW.ownership_key = 'tenant:' || NEW.tenant_id)) THEN RAISE(ABORT, 'invalid media ownership') END; END");
            }

            return;
        }
        $expression = $driver === 'mysql'
            ? "((ownership_key = 'platform' AND tenant_id IS NULL) OR (tenant_id IS NOT NULL AND ownership_key = CONCAT('tenant:', tenant_id)))"
            : "((ownership_key = 'platform' AND tenant_id IS NULL) OR (tenant_id IS NOT NULL AND ownership_key = 'tenant:' || tenant_id))";
        try {
            $connection->statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        } catch (Throwable $exception) {
            if (! str_contains(mb_strtolower($exception->getMessage()), 'already exists') && ! str_contains(mb_strtolower($exception->getMessage()), 'duplicate')) {
                throw $exception;
            }
        }
    }

    /** Remove the portable mixed ownership guard. */
    private function dropOwnershipCheck(Connection $connection, string $table): void
    {
        $name = $table.'_ownership_check';
        if ($connection->getDriverName() === 'sqlite') {
            $connection->unprepared("DROP TRIGGER IF EXISTS {$name}_insert");
            $connection->unprepared("DROP TRIGGER IF EXISTS {$name}_update");
        }
    }
};
