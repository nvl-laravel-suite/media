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
        if (Schema::connection(PackageStorage::connection('media'))->hasTable(MediaTables::get(MediaTables::I18n))) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }

        Schema::connection(PackageStorage::connection('media'))->create(MediaTables::get(MediaTables::I18n), function (Blueprint $table) {
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
                ->on(MediaTables::get(MediaTables::Media))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection(PackageStorage::connection('media'))->dropIfExists(MediaTables::get(MediaTables::I18n));
    }
};
