<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Definitions\Tables\MediaTables;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(MediaTables::MEDIA_ASSOCIATIONS)) {
            return;
        }

        Schema::create(MediaTables::MEDIA_ASSOCIATIONS, function (Blueprint $table) {
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
                ->on(MediaTables::MEDIA)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MediaTables::MEDIA_ASSOCIATIONS);
    }
};
