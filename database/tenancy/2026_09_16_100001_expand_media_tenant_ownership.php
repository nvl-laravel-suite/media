<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Support\MediaConfiguration;

return new class extends Migration
{
    /** Add nullable adoption columns without changing legacy row visibility. */
    public function up(): void
    {
        $mixed = config('tenancy.sharing.media') === 'copy';
        $platform = config('tenancy.resources.media') === 'platform';
        $this->expand(MediaTables::Media, function (Blueprint $table) use ($mixed): void {
            $table->uuid('tenant_id')->nullable()->index('media_tenant_idx');
            if ($mixed) {
                $table->string('ownership_key', 44)->nullable()->index('media_ownership_idx');
            }
            $table->string('storage_path', 1024)->nullable();
            $table->uuid('catalog_import_key')->nullable();
            $table->uuid('catalog_source_id')->nullable();
            $table->unsignedBigInteger('catalog_source_revision')->nullable();
            $table->char('catalog_source_digest', 64)->nullable();
        });
        foreach ([MediaTables::Associations, MediaTables::ImageVariations, MediaTables::I18n] as $child) {
            $this->expand($child, function (Blueprint $table) use ($mixed, $child): void {
                $table->uuid('tenant_id')->nullable()->index($child.'_tenant_idx');
                if ($mixed) {
                    $table->string('ownership_key', 44)->nullable()->index($child.'_ownership_idx');
                }
            });
        }
        $this->expand(MediaTables::MultipartUploads, function (Blueprint $table) use ($platform): void {
            $table->uuid('tenant_id')->nullable()->index('media_multipart_tenant_idx');
            if ($platform) {
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

        if (! Schema::hasTable(MediaTables::TenantAdoptionCopies)) {
            Schema::create(MediaTables::TenantAdoptionCopies, static function (Blueprint $table): void {
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
        Schema::dropIfExists(MediaTables::TenantAdoptionCopies);
        $this->contract(MediaTables::Media, ['tenant_id', 'ownership_key', 'storage_path', 'catalog_import_key', 'catalog_source_id', 'catalog_source_revision', 'catalog_source_digest']);
        foreach ([MediaTables::Associations, MediaTables::ImageVariations, MediaTables::I18n] as $child) {
            $this->contract($child, ['tenant_id', 'ownership_key']);
        }
        $this->contract(MediaTables::MultipartUploads, ['tenant_id', 'ownership_key']);
        $schema = Schema::connection(MediaConfiguration::ownerSlotOperationConnection());
        $table = MediaConfiguration::ownerSlotOperationTable();
        if ($schema->hasTable($table) && $schema->hasColumn($table, 'tenant_id')) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn('tenant_id'));
        }
    }

    /** Add expansion columns only when the target table exists and is untouched. */
    private function expand(string $table, Closure $callback): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'tenant_id')) {
            Schema::table($table, $callback);
        }
    }

    /** @param list<string> $columns */
    private function contract(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $present = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn($table, $column)));
        if ($present !== []) {
            Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn($present));
        }
    }
};
