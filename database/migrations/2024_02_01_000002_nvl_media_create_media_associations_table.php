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

    public function up(): void
    {
        if (Schema::connection(PackageStorage::connection('media'))->hasTable(MediaTables::get(MediaTables::Associations))) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }

        Schema::connection(PackageStorage::connection('media'))->create(MediaTables::get(MediaTables::Associations), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_id');
            $table->string('associable_type');
            $table->string('associable_id')
                ->comment('Owner key supporting integer, UUID, ULID, and string model identifiers.');

            $table->string('collection', 50)->default('default')
                ->comment('Logical grouping name for the association.');

            $table->string('locale', 5)->nullable()
                ->comment('Locale code for locale-specific associations.');

            $table->unsignedInteger('order')->default(0)
                ->comment('Display order position within the collection.');

            $table->boolean('is_active')->default(true)
                ->comment('Whether this association is the active collection member.');

            $table->timestamp('replaced_at')->nullable()
                ->comment('When this association was superseded by a single-file replacement.');

            $table->json('metadata')->nullable()
                ->comment('Additional pivot metadata for the association.');

            $table->timestamps();

            $table->unique(['media_id', 'associable_type', 'associable_id', 'collection'], 'media_assoc_unique');
            $table->index(['associable_type', 'associable_id', 'collection'], 'media_assoc_morph_collection');
            $table->index(
                ['associable_type', 'associable_id', 'collection', 'is_active'],
                'media_assoc_owner_collection_active_idx',
            );
            $table->index('collection');

            $table->foreign('media_id')
                ->references('id')
                ->on(MediaTables::get(MediaTables::Media))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection(PackageStorage::connection('media'))->dropIfExists(MediaTables::get(MediaTables::Associations));
    }
};
