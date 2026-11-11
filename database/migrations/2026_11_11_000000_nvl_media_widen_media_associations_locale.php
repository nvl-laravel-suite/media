<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

    /** Admit the family's locale codes, which reach 35 characters (for example sr-Latn-RS). */
    public function up(): void
    {
        $this->resize(35);
    }

    /** Refuse to narrow the column while a stored locale would be truncated or rejected. */
    public function down(): void
    {
        if (! $this->associationsExist()) {
            return;
        }

        $hasLongLocale = DB::connection(PackageStorage::connection('media'))
            ->table(MediaTables::get(MediaTables::Associations))
            ->whereRaw('LENGTH(locale) > 5')
            ->exists();

        if ($hasLongLocale) {
            throw new LogicException('Cannot narrow the media association locale while stored locales are longer than five characters.');
        }

        $this->resize(5);
    }

    /** Leave a connection that does not hold the associations table untouched. */
    private function resize(int $length): void
    {
        if (! $this->associationsExist()) {
            return;
        }

        Schema::connection(PackageStorage::connection('media'))->table(MediaTables::get(MediaTables::Associations), function (Blueprint $table) use ($length): void {
            $table->string('locale', $length)->nullable()
                ->comment('Locale code for locale-specific associations.')
                ->change();
        });
    }

    private function associationsExist(): bool
    {
        return Schema::connection(PackageStorage::connection('media'))->hasTable(MediaTables::get(MediaTables::Associations));
    }
};
