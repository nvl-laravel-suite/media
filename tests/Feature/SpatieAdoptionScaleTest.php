<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Services\MediaImport;
use Nvl\Support\Enums\SystemActor;

/** Stage $count legacy media rows, each with one translation and one variation, plus their files. */
function stageLegacySpatieMedia(int $count): void
{
    config(['nvl-media.root_folder' => '']);
    Storage::fake('public');
    Schema::create('adoption_legacy', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('file_name');
        $table->text('mime_type');
        $table->string('disk');
        $table->string('digest');
        $table->unsignedBigInteger('size');
    });
    Schema::create('adoption_legacy_translations', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('media_id');
        $table->text('title');
    });
    Schema::create('adoption_legacy_variations', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('media_id');
        $table->string('label');
        $table->string('storage_path');
    });

    foreach (array_chunk(range(1, $count), 200) as $ids) {
        DB::table('adoption_legacy')->insert(array_map(static fn (int $id): array => ['id' => $id, 'file_name' => 'file.txt', 'mime_type' => 'text/plain', 'disk' => 'public', 'digest' => hash('sha256', 'x'), 'size' => 1], $ids));
        DB::table('adoption_legacy_translations')->insert(array_map(static fn (int $id): array => ['id' => $id, 'media_id' => $id, 'title' => "Title {$id}"], $ids));
        DB::table('adoption_legacy_variations')->insert(array_map(static fn (int $id): array => ['id' => $id, 'media_id' => $id, 'label' => 'thumb', 'storage_path' => "{$id}/thumb.txt"], $ids));

        foreach ($ids as $id) {
            Storage::disk('public')->put("{$id}/file.txt", 'x');
            Storage::disk('public')->put("{$id}/thumb.txt", 'x');
        }
    }

}

it('adopts a legacy table in bounded chunks instead of one query over every row', function (): void {
    stageLegacySpatieMedia(520);
    $largestBindingCount = 0;
    $unboundedSourceReads = 0;
    DB::listen(function (QueryExecuted $query) use (&$largestBindingCount, &$unboundedSourceReads): void {
        $isSelect = str_starts_with(strtolower($query->sql), 'select');

        if ($isSelect) {
            $largestBindingCount = max($largestBindingCount, count($query->bindings));
        }

        if (preg_match('/^select .* from\s+["`]?adoption_legacy/i', $query->sql) === 1 && ! str_contains(strtolower($query->sql), 'limit') && ! str_contains(strtolower($query->sql), ' in (')) {
            $unboundedSourceReads++;
        }
    });

    $applied = app(MediaImport::class)->adopt(SystemActor::Background, 'adoption_legacy', 'adoption_legacy_translations', 'adoption_legacy_variations', apply: true);

    expect($applied['ready'])->toBeTrue()
        ->and([$applied['sourceMedia'], $applied['matchedMedia']])->toBe([520, 520])
        ->and([$applied['sourceTranslations'], $applied['matchedTranslations']])->toBe([520, 520])
        ->and([$applied['sourceVariations'], $applied['matchedVariations']])->toBe([520, 520])
        ->and(Media::query()->count())->toBe(520)
        ->and(MediaTranslation::query()->count())->toBe(520)
        ->and(MediaImageVariation::query()->count())->toBe(520)
        ->and($unboundedSourceReads)->toBe(0)
        ->and($largestBindingCount)->toBeLessThanOrEqual(251);
});

it('rolls back every chunk when a later row blocks an applied adoption', function (): void {
    stageLegacySpatieMedia(300);
    Storage::disk('public')->delete('299/file.txt');

    expect(fn () => app(MediaImport::class)->adopt(SystemActor::Background, 'adoption_legacy', apply: true))
        ->toThrow(LogicException::class, 'cannot apply');
    expect(Media::query()->count())->toBe(0);

    $dryRun = app(MediaImport::class)->adopt(SystemActor::Background, 'adoption_legacy');
    expect($dryRun['ready'])->toBeFalse()->and($dryRun['missingPaths'])->toBe(['public:299/file.txt'])->and($dryRun['sourceMedia'])->toBe(300);
});

it('reports a companion row whose legacy media row does not exist', function (): void {
    stageLegacySpatieMedia(2);
    DB::table('adoption_legacy_variations')->insert(['id' => 99, 'media_id' => 404, 'label' => 'thumb', 'storage_path' => '404/thumb.txt']);

    $dryRun = app(MediaImport::class)->adopt(SystemActor::Background, 'adoption_legacy', variationTable: 'adoption_legacy_variations');

    expect($dryRun['ready'])->toBeFalse()->and($dryRun['errors'])->toBe(['variation [99]: The referenced legacy media row was not mapped.']);
});

it('reads staged tables and writes adopted rows on the Media connection, not the default one', function (): void {
    stageLegacySpatieMedia(3);
    $mediaConnection = DB::getDefaultConnection();
    config(['nvl-media.connection' => $mediaConnection, 'database.connections.adoption_default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'database.default' => 'adoption_default']);

    try {
        $applied = app(MediaImport::class)->adopt(SystemActor::Background, 'adoption_legacy', apply: true);
    } finally {
        config(['database.default' => $mediaConnection]);
        DB::purge('adoption_default');
    }

    expect($applied['matchedMedia'])->toBe(3)->and(Media::query()->count())->toBe(3);
});

it('reports legacy values too long for their canonical column during the dry run', function (): void {
    stageLegacySpatieMedia(1);
    DB::table('adoption_legacy_translations')->where('id', 1)->update(['title' => str_repeat('t', 256)]);
    DB::table('adoption_legacy')->where('id', 1)->update(['mime_type' => str_repeat('m', 256)]);

    $dryRun = app(MediaImport::class)->adopt(SystemActor::Background, 'adoption_legacy', 'adoption_legacy_translations');

    expect($dryRun['ready'])->toBeFalse()->and($dryRun['errors'])->toBe([
        'media [1]: Legacy MIME type exceeds the canonical 255-character limit.',
        'translation [1]: Legacy translation title exceeds the canonical 255-character limit.',
    ]);
});
