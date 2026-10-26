<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('media');
    }

    /** Create the concrete recipient grant ledger. */
    public function up(): void
    {
        if (! Schema::connection(PackageStorage::connection('media'))->hasTable(MediaTables::get(MediaTables::TenantGrantLocks))) {
            Schema::connection(PackageStorage::connection('media'))->create(MediaTables::get(MediaTables::TenantGrantLocks), static function (Blueprint $table): void {
                $table->uuid('tenant_id');
                $table->uuid('media_id');
                $table->timestamps();
                $table->primary(['tenant_id', 'media_id'], 'media_tenant_grant_locks_primary');
            });
        }
        if (Schema::connection(PackageStorage::connection('media'))->hasTable(MediaTables::get(MediaTables::TenantGrants))) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }
        Schema::connection(PackageStorage::connection('media'))->create(MediaTables::get(MediaTables::TenantGrants), static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('media_id');
            $table->unsignedBigInteger('source_revision');
            $table->unsignedBigInteger('revision')->default(1);
            $table->boolean('enabled')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'media_id'], 'media_tenant_grants_identity_unique');
            $table->unique(['tenant_id', 'id'], 'media_tenant_grants_tenant_id_unique');
            $table->index(['tenant_id', 'enabled', 'created_at'], 'media_tenant_grants_lookup_idx');
            $table->foreign('media_id')->references('id')->on(MediaTables::get(MediaTables::Media))->cascadeOnDelete();
        });
    }

    /** Drop the grant ledger. */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('media'))->dropIfExists(MediaTables::get(MediaTables::TenantGrants));
        Schema::connection(PackageStorage::connection('media'))->dropIfExists(MediaTables::get(MediaTables::TenantGrantLocks));
    }
};
