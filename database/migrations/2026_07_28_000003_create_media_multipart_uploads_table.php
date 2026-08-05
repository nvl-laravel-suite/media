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
        if (Schema::hasTable(MediaTables::MEDIA_MULTIPART_UPLOADS)) {
            return;
        }

        Schema::create(MediaTables::MEDIA_MULTIPART_UPLOADS, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('provider_state')->nullable();
            $table->string('disk', 64);
            $table->string('object_key', 1024);
            $table->char('object_key_hash', 64);
            $table->string('display_filename');
            $table->string('canonical_extension', 10);
            $table->string('declared_mime');
            $table->unsignedBigInteger('expected_size');
            $table->char('expected_checksum', 64);
            $table->string('visibility', 16);
            $table->string('uploader_id')->nullable();
            $table->string('uploader_type')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('part_size');
            $table->unsignedInteger('expected_parts');
            $table->unsignedBigInteger('minimum_part_size');
            $table->unsignedBigInteger('maximum_part_size');
            $table->unsignedInteger('maximum_parts');
            $table->json('signed_parts')->nullable();
            $table->string('status', 32);
            $table->uuid('completed_media_id')->nullable();
            $table->string('provider_object_identity')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->json('failure_context')->nullable();
            $table->timestamps();

            $table->unique(
                ['disk', 'object_key_hash'],
                'media_multipart_disk_object_hash_unique',
            );
            $table->index(
                ['uploader_type', 'uploader_id', 'created_at'],
                'media_multipart_actor_created_idx',
            );
            $table->index(['status', 'expires_at'], 'media_multipart_status_expiry_idx');
            $table->unique('completed_media_id', 'media_multipart_completed_media_unique');

            $table->foreign('completed_media_id')
                ->references('id')
                ->on(MediaTables::MEDIA)
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MediaTables::MEDIA_MULTIPART_UPLOADS);
    }
};
