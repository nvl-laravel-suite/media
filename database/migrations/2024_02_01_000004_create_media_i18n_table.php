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
        if (Schema::hasTable(MediaTables::I18n)) {
            return;
        }

        Schema::create(MediaTables::I18n, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_id');

            $table->string('locale', 35)
                ->comment('Locale code for the translation.');

            $table->string('title')->nullable()
                ->comment('Translated title for the media.');

            $table->string('alt')->nullable()
                ->comment('Translated alt text for accessibility.');

            $table->text('caption')->nullable()
                ->comment('Translated caption for the media.');

            $table->text('description')->nullable()
                ->comment('Translated long-form description for the media.');

            $table->timestamps();

            $table->unique(['media_id', 'locale']);
            $table->index('locale', 'media_i18n_locale_idx');

            $table->foreign('media_id')
                ->references('id')
                ->on(MediaTables::Media)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MediaTables::I18n);
    }
};
