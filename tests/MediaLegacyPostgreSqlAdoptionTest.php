<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Models\Media;
use Nvl\Media\Tests\Fixtures\StandaloneMediaOwner;
use Nvl\Media\Tests\StandaloneMediaTestCase;
use Nvl\Support\Adoption\AdoptionGate;
use Nvl\Support\Adoption\AdoptionStore;

uses(StandaloneMediaTestCase::class);

/**
 * Directory of the native Media migrations.
 */
function legacyPostgreSqlMediaMigrations(): string
{
    return dirname(__DIR__).'/database/migrations';
}

/**
 * Create GCT's px_media tables with the index names the native migrations also use, plus one committed graph.
 *
 * @return array{asset: string, owner: string}
 */
function createGctMediaTables(): array
{
    Schema::create('px_media', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        foreach (['filename', 'hash', 'extension', 'mime_type', 'disk', 'folder', 'type', 'digest', 'uploaded_by'] as $column) {
            $table->string($column)->nullable();
        }
        $table->unsignedBigInteger('size')->nullable();
        $table->boolean('is_public')->default(false);
        $table->json('tags')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->index(['digest', 'disk', 'is_public'], 'media_dedup_digest_disk_public');
    });
    Schema::create('px_media_associations', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('media_id');
        $table->string('associable_type');
        $table->string('associable_id');
        $table->string('collection', 50);
        $table->string('locale', 5)->nullable();
        $table->unsignedInteger('order')->default(0);
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->unique(['media_id', 'associable_type', 'associable_id', 'collection'], 'media_assoc_unique');
    });
    Schema::create('px_media_image_variations', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('media_id');
        $table->string('label', 30);
        foreach (['width', 'height', 'size', 'quality'] as $column) {
            $table->unsignedInteger($column);
        }
        $table->string('format', 10);
        $table->timestamps();
        $table->index('label', 'media_var_label_idx');
    });
    Schema::create('px_media_translations', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('media_id');
        $table->string('locale', 5);
        foreach (['title', 'alt', 'caption'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
    });

    $time = '2026-05-12 11:12:13';
    $timestamps = ['created_at' => $time, 'updated_at' => $time];
    $owner = StandaloneMediaOwner::query()->create(['name' => 'GCT owner']);
    $asset = (string) Str::uuid();
    DB::table('px_media')->insert(['id' => $asset, 'filename' => 'cover.jpg', 'hash' => 'cover-hash.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 4, 'disk' => 'public', 'folder' => 'photos', 'is_public' => true, 'type' => 'image', 'digest' => hash('sha256', 'file'), 'tags' => '["gct"]', 'metadata' => '{"kind":"cover"}', ...$timestamps]);
    DB::table('px_media_associations')->insert(['id' => (string) Str::uuid(), 'media_id' => $asset, 'associable_type' => 'gct-owner', 'associable_id' => (string) $owner->getKey(), 'collection' => 'gallery', 'locale' => 'bg', 'order' => 1, 'metadata' => '{"slot":"cover"}', ...$timestamps]);
    DB::table('px_media_image_variations')->insert(['id' => (string) Str::uuid(), 'media_id' => $asset, 'label' => 'small', 'width' => 100, 'height' => 100, 'size' => 5, 'format' => 'webp', 'quality' => 80, ...$timestamps]);
    DB::table('px_media_translations')->insert(['id' => (string) Str::uuid(), 'media_id' => $asset, 'locale' => 'bg', 'title' => 'Корица', 'alt' => 'Снимка', 'caption' => '', ...$timestamps]);
    Storage::disk('public')->put('legacy/photos/cover-hash.jpg', 'file');
    Storage::disk('public')->put('legacy/photos/conversions/cover-hash-small.webp', 'thumb');

    return ['asset' => $asset, 'owner' => (string) $owner->getKey()];
}

it('installs and adopts native Media next to GCT tables after renaming their colliding PostgreSQL indexes', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL index names are schema-wide; this cutover runs in the PostgreSQL matrix.');
    }
    Storage::fake('public');
    config(['nvl-media.root_folder' => 'legacy', 'nvl-media.delivery.disks.public.public.mode' => 'direct']);
    $migrations = ['--path' => legacyPostgreSqlMediaMigrations(), '--realpath' => true];
    Artisan::call('migrate:rollback', $migrations);
    expect(Schema::hasTable(MediaTables::get(MediaTables::Media)))->toBeFalse();

    try {
        ['asset' => $asset, 'owner' => $owner] = createGctMediaTables();
        expect(fn () => Artisan::call('migrate', $migrations))->toThrow(QueryException::class, 'already exists');

        $colliding = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', 'like', 'px\_media%')->where('indexname', 'not like', 'px\_%')->orderBy('indexname')->pluck('indexname')->all();
        expect($colliding)->toBe(['media_assoc_unique', 'media_dedup_digest_disk_public', 'media_var_label_idx']);
        foreach ($colliding as $index) {
            DB::statement(sprintf('ALTER INDEX %s RENAME TO %s', $index, 'px_'.$index));
        }
        Artisan::call('migrate', $migrations);

        $core = dirname((string) (new ReflectionClass(AdoptionStore::class))->getFileName(), 3).'/database/migrations';
        Artisan::call('migrate', ['--path' => $core, '--realpath' => true]);
        $manifest = (string) tempnam(sys_get_temp_dir(), 'nvl-media-gct-');
        file_put_contents($manifest, json_encode(['root_folder' => 'legacy', 'owner_types' => ['gct-owner' => StandaloneMediaOwner::class]], JSON_THROW_ON_ERROR));
        $adopt = static function (string $phase, array $options = []) use ($manifest): array {
            $status = Artisan::call('nvl:adopt', ['package' => 'media', 'phase' => $phase, 'manifest' => $manifest, '--format' => 'json', ...$options]);

            return ['status' => $status, ...json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
        };

        try {
            $plan = $adopt('plan');
            expect($plan['collisions'])->toBe([]);
            expect($adopt('apply', ['--fingerprint' => $plan['fingerprint']])['state'])->toBe('applied')
                ->and($adopt('verify')['verification']['passed'])->toBeTrue()
                ->and($adopt('activate', ['--fingerprint' => $plan['fingerprint']])['state'])->toBe('active')
                ->and(app(AdoptionGate::class)->isActive('media'))->toBeTrue();
        } finally {
            @unlink($manifest);
        }

        $adopted = Media::query()->findOrFail($asset);
        expect($adopted->getUrl())->toBe(Storage::disk('public')->url('legacy/photos/cover-hash.jpg'))
            ->and($adopted->associations()->sole()->associable_id)->toBe($owner)
            ->and($adopted->translations()->sole()->title)->toBe('Корица')
            ->and(DB::table('px_media')->count())->toBe(1);
    } finally {
        foreach (['px_media_translations', 'px_media_image_variations', 'px_media_associations', 'px_media'] as $table) {
            Schema::dropIfExists($table);
        }
    }
});
