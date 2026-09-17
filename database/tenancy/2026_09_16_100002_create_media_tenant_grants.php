<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Definitions\Tables\MediaTables;

return new class extends Migration
{
    /** Create the concrete recipient grant ledger. */
    public function up(): void
    {
        if (! Schema::hasTable(MediaTables::TenantGrantLocks)) {
            Schema::create(MediaTables::TenantGrantLocks, static function (Blueprint $table): void {
                $table->uuid('tenant_id');
                $table->uuid('media_id');
                $table->timestamps();
                $table->primary(['tenant_id', 'media_id'], 'media_tenant_grant_locks_primary');
            });
        }
        if (Schema::hasTable(MediaTables::TenantGrants)) {
            return;
        }
        Schema::create(MediaTables::TenantGrants, static function (Blueprint $table): void {
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
            $table->foreign('media_id')->references('id')->on(MediaTables::Media)->cascadeOnDelete();
        });
    }

    /** Drop the grant ledger. */
    public function down(): void
    {
        Schema::dropIfExists(MediaTables::TenantGrants);
        Schema::dropIfExists(MediaTables::TenantGrantLocks);
    }
};
