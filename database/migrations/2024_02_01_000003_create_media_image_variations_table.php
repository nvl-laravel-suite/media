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
        if (Schema::hasTable(MediaTables::ImageVariations)) {
            return;
        }

        Schema::create(MediaTables::ImageVariations, function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('media_id')
                ->comment('FK to the parent media record.');

            $table->string('label', 30)
                ->comment('Variation preset name: thumb, small, medium, large.');

            $table->string('storage_path', 1024)->nullable()
                ->comment('Immutable object path for this generated variation.');

            $table->string('status', 32)->default('available')
                ->comment('Generation lifecycle state for this variation.');

            $table->unsignedInteger('width')
                ->comment('Width of the variation in pixels.');

            $table->unsignedInteger('height')
                ->comment('Height of the variation in pixels.');

            $table->unsignedBigInteger('size')->default(0)
                ->comment('File size in bytes.');

            $table->string('format', 10)->default('webp')
                ->comment('Output format: webp, jpg, png, etc.');

            $table->unsignedTinyInteger('quality')->default(80)
                ->comment('Quality setting used during generation (0-100).');

            $table->unsignedBigInteger('source_revision')->default(1)
                ->comment('Media revision from which the variation was generated.');

            $table->unsignedSmallInteger('attempts')->default(0)
                ->comment('Number of generation attempts made for this variation.');

            $table->json('failure_context')->nullable()
                ->comment('Internal diagnostic context for the latest generation failure.');

            $table->timestamps();

            $table->unique(['media_id', 'label']);
            $table->index('label', 'media_var_label_idx');

            $table->foreign('media_id')
                ->references('id')
                ->on(MediaTables::Media)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MediaTables::ImageVariations);
    }
};
