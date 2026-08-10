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
        if (Schema::hasTable(MediaTables::MEDIA)) {
            return;
        }

        Schema::create(MediaTables::MEDIA, function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('filename')
                ->comment('Original display filename of the uploaded file.');

            $table->string('hash')
                ->comment('Hashed filename used for storage on disk.');

            $table->string('extension', 10)
                ->comment('File extension (e.g., jpg, png, pdf).');

            $table->string('mime_type')
                ->comment('MIME type of the file (e.g., image/jpeg).');

            $table->unsignedBigInteger('size')
                ->comment('File size in bytes.');

            $table->unsignedInteger('width')->nullable()
                ->comment('Intrinsic width in pixels when the media has visual dimensions.');

            $table->unsignedInteger('height')->nullable()
                ->comment('Intrinsic height in pixels when the media has visual dimensions.');

            $table->unsignedBigInteger('duration_ms')->nullable()
                ->comment('Duration in milliseconds for time-based media.');

            $table->string('disk', 25)
                ->comment('Storage disk name (e.g., public, s3).');

            $table->string('folder')->nullable()
                ->comment('Storage folder path within the disk.');

            $table->boolean('is_public')->default(false)
                ->comment('Whether the file is publicly accessible.');

            $table->string('visibility', 16)->default('private')
                ->comment('Canonical delivery visibility: private or public.');

            $table->string('status', 32)->default('available')
                ->comment('Lifecycle state controlling association, processing, and delivery.');

            $table->unsignedBigInteger('revision')->default(1)
                ->comment('Source revision used for optimistic concurrency and variation invalidation.');

            $table->timestamp('available_at')->nullable()
                ->comment('When the verified binary became available for use.');

            $table->timestamp('quarantined_at')->nullable()
                ->comment('When the binary entered quarantine.');

            $table->string('failure_code', 80)->nullable()
                ->comment('Stable machine-readable code for the latest lifecycle failure.');

            $table->json('failure_context')->nullable()
                ->comment('Internal diagnostic context for the latest lifecycle failure.');

            $table->string('type', 15)
                ->comment('Media type classification (image, video, document, etc.).');

            $table->string('digest')
                ->comment('Content digest hash for deduplication.');

            $table->json('tags')->nullable()
                ->comment('Classification tags for organization.');

            $table->json('metadata')->nullable()
                ->comment('Arbitrary key-value metadata.');

            $table->json('variation_definitions')->nullable()
                ->comment('Normalized named variation definitions required for deterministic regeneration.');

            $table->string('uploaded_by')->nullable()
                ->comment('Authentication identifier of the uploader without assuming a key strategy.');
            $table->string('uploaded_by_type')->nullable()
                ->comment('Morph type of the uploader model when one is available.');

            $table->uuid('upload_session_id')->nullable()
                ->comment('Idempotent multipart upload session that produced this media.');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['filename', 'type']);
            $table->index('hash', 'media_hash_idx');
            $table->index('disk');
            $table->index('type');
            $table->index(['uploaded_by_type', 'uploaded_by'], 'media_uploader_index');
            $table->index('digest');
            $table->index(['digest', 'disk', 'is_public'], 'media_dedup_digest_disk_public');
            $table->index('is_public', 'media_is_public_idx');
            $table->index(['status', 'visibility'], 'media_status_visibility_idx');
            $table->index(['visibility', 'digest', 'disk'], 'media_visibility_digest_disk_idx');
            $table->index(['visibility', 'created_at'], 'media_visibility_created_idx');
            $table->index(
                ['uploaded_by_type', 'uploaded_by', 'created_at'],
                'media_uploader_created_idx',
            );
            $table->index(['disk', 'created_at'], 'media_disk_created_idx');
            $table->index(['type', 'created_at'], 'media_type_created_idx');
            $table->index(['status', 'created_at'], 'media_status_created_idx');
            $table->unique('upload_session_id', 'media_upload_session_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MediaTables::MEDIA);
    }
};
