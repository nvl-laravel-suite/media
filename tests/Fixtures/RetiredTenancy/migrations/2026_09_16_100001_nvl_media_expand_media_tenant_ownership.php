<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('media');
    }

    /** Add nullable adoption columns without changing legacy row visibility. */
    public function up(): void
    {
        $mixed = config('nvl-tenancy.sharing.media') === 'copy';
        $platform = config('nvl-tenancy.resources.media') === 'platform';
        $partitioned = $mixed || $platform;
        $this->expand(MediaTables::get(MediaTables::Media), function (Blueprint $table) use ($partitioned): void {
            $table->uuid('tenant_id')->nullable()->index('media_tenant_idx');
            if ($partitioned) {
                $table->string('ownership_key', 44)->nullable()->index('media_ownership_idx');
            }
            $table->string('storage_path', 1024)->nullable();
            $table->uuid('catalog_import_key')->nullable();
            $table->uuid('catalog_source_id')->nullable();
            $table->unsignedBigInteger('catalog_source_revision')->nullable();
            $table->char('catalog_source_digest', 64)->nullable();
        });
        foreach ([MediaTables::get(MediaTables::Associations), MediaTables::get(MediaTables::ImageVariations), MediaTables::get(MediaTables::I18n)] as $child) {
            $this->expand($child, function (Blueprint $table) use ($partitioned, $child): void {
                $table->uuid('tenant_id')->nullable()->index($child.'_tenant_idx');
                if ($partitioned) {
                    $table->string('ownership_key', 44)->nullable()->index($child.'_ownership_idx');
                }
            });
        }
        $this->expand(MediaTables::get(MediaTables::MultipartUploads), function (Blueprint $table) use ($partitioned): void {
            $table->uuid('tenant_id')->nullable()->index('media_multipart_tenant_idx');
            if ($partitioned) {
                $table->string('ownership_key', 44)->nullable()->index('media_multipart_ownership_idx');
            }
        });

        $operationSchema = Schema::connection(MediaConfiguration::ownerSlotOperationConnection());
        $operationTable = MediaConfiguration::ownerSlotOperationTable();
        if ($operationSchema->hasTable($operationTable) && ! $operationSchema->hasColumn($operationTable, 'tenant_id')) {
            $operationSchema->table($operationTable, static function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable()->index('media_owner_slot_tenant_idx');
            });
        }

        if (! Schema::connection(PackageStorage::connection('media'))->hasTable(MediaTables::get(MediaTables::TenantAdoptionCopies))) {
            Schema::connection(PackageStorage::connection('media'))->create(MediaTables::get(MediaTables::TenantAdoptionCopies), static function (Blueprint $table): void {
                $table->uuid('adoption_run_id');
                $table->uuid('source_id');
                $table->uuid('tenant_id');
                $table->uuid('destination_id');
                $table->string('status', 32);
                $table->string('disk', 64)->nullable();
                $table->string('storage_path', 1024)->nullable();
                $table->char('digest', 64)->nullable();
                $table->timestamp('checksum_verified_at')->nullable();
                $table->timestamps();
                $table->primary(['adoption_run_id', 'source_id', 'tenant_id'], 'media_adoption_copies_primary');
                $table->unique(['adoption_run_id', 'destination_id'], 'media_adoption_destination_unique');
            });
        }
    }

    /** Remove only the nullable adoption expansion. */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('media'))->dropIfExists(MediaTables::get(MediaTables::TenantAdoptionCopies));
        $this->contract(MediaTables::get(MediaTables::Media), ['tenant_id', 'ownership_key', 'storage_path', 'catalog_import_key', 'catalog_source_id', 'catalog_source_revision', 'catalog_source_digest']);
        foreach ([MediaTables::get(MediaTables::Associations), MediaTables::get(MediaTables::ImageVariations), MediaTables::get(MediaTables::I18n)] as $child) {
            $this->contract($child, ['tenant_id', 'ownership_key']);
        }
        $this->contract(MediaTables::get(MediaTables::MultipartUploads), ['tenant_id', 'ownership_key']);
        $schema = Schema::connection(MediaConfiguration::ownerSlotOperationConnection());
        $table = MediaConfiguration::ownerSlotOperationTable();
        if ($schema->hasTable($table) && $schema->hasColumn($table, 'tenant_id')) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn('tenant_id'));
        }
    }

    /** Add expansion columns only when the target table exists and is untouched. */
    private function expand(string $table, Closure $callback): void
    {
        if (Schema::connection(PackageStorage::connection('media'))->hasTable($table) && ! Schema::connection(PackageStorage::connection('media'))->hasColumn($table, 'tenant_id')) {
            Schema::connection(PackageStorage::connection('media'))->table($table, $callback);
        }
    }

    /** @param list<string> $columns */
    private function contract(string $table, array $columns): void
    {
        if (! Schema::connection(PackageStorage::connection('media'))->hasTable($table)) {
            return;
        }
        $present = array_values(array_filter($columns, static fn (string $column): bool => Schema::connection(PackageStorage::connection('media'))->hasColumn($table, $column)));
        if ($present !== []) {
            Schema::connection(PackageStorage::connection('media'))->table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn($present));
        }
    }
};
