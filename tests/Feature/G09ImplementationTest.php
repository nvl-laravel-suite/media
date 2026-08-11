<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\AdoptSpatieMediaAction;
use Nvl\Media\Actions\RelocateMediaAction;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Facades\Media as MediaFacade;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Services\MediaDoctor;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaUrlResolver;
use Nvl\Media\Tests\Stubs\TestMediaModel;

beforeEach(function (): void {
    Storage::fake('public');

    config([
        'media.allowed_disks' => ['public'],
        'media.auto_generate_variations' => false,
        'media.auto_generate_conversions' => false,
        'media.output_conversion.enabled' => false,
    ]);
});

function g09Media(array $overrides = []): Media
{
    return Media::create(array_merge([
        'filename' => 'document.pdf',
        'hash' => 'document.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size' => 16,
        'disk' => 'public',
        'folder' => 'documents',
        'is_public' => false,
        'type' => MediaType::DOCUMENT,
        'digest' => hash('sha256', 'document'),
    ], $overrides));
}

it('allows repeated storage hashes without imposing a global uniqueness constraint', function (): void {
    $first = g09Media(['folder' => 'first']);
    $second = g09Media(['folder' => 'second']);

    expect($first->hash)->toBe($second->hash)
        ->and(Media::query()->where('hash', 'document.pdf')->count())->toBe(2);
});

it('uploads generated binary content through MIME detection and the canonical pipeline', function (): void {
    $owner = TestMediaModel::create(['name' => 'Generated report owner']);
    $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";

    $media = $owner->addMediaFromBinary($pdf, 'generated-report.pdf', 'application/pdf')
        ->withoutVariations()
        ->slot('documents');

    expect($media->filename)->toBe('generated-report.pdf')
        ->and($media->mime_type)->toBe('application/pdf')
        ->and($media->digest)->toBe(hash('sha256', $pdf));
    Storage::disk('public')->assertExists($media->buildPath());
});

it('relocates one media record and its variations with revision locking', function (): void {
    config(['filesystems.disks.archive' => [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/archive'),
        'throw' => true,
    ]]);
    Storage::fake('archive');
    config(['media.allowed_disks' => ['public', 'archive']]);
    $contents = 'relocation-source';
    $media = g09Media([
        'size' => strlen($contents),
        'digest' => hash('sha256', $contents),
    ]);
    $variation = MediaImageVariation::create([
        'media_id' => $media->id,
        'label' => 'thumb',
        'storage_path' => 'media/documents/conversions/document-thumb.webp',
        'status' => 'available',
        'width' => 100,
        'height' => 100,
        'size' => 9,
        'format' => 'webp',
        'quality' => 80,
        'source_revision' => 1,
    ]);
    Storage::disk('public')->put($media->buildPath(), $contents);
    Storage::disk('public')->put($variation->getPath(), 'variation');

    $relocated = app(RelocateMediaAction::class)->execute(
        $media,
        'archive',
        MediaVisibility::Public,
        expectedRevision: 1,
    );

    expect($relocated->disk)->toBe('archive')
        ->and($relocated->visibility)->toBe(MediaVisibility::Public)
        ->and($relocated->revision)->toBe(2)
        ->and($relocated->imageVariations->first()?->source_revision)->toBe(2);
    Storage::disk('archive')->assertExists($media->buildPath());
    Storage::disk('archive')->assertExists($variation->getPath());

    expect(fn () => app(RelocateMediaAction::class)->execute(
        $relocated,
        'public',
        MediaVisibility::Private,
        expectedRevision: 1,
    ))->toThrow(MediaUploadException::class, 'changed before relocation');
});

it('cleans staged relocation copies when a later source object is missing', function (): void {
    config([
        'filesystems.disks.archive' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/archive'),
            'throw' => true,
        ],
        'media.allowed_disks' => ['public', 'archive'],
    ]);
    Storage::fake('archive');
    $media = g09Media();
    MediaImageVariation::create([
        'media_id' => $media->id,
        'label' => 'missing-thumb',
        'storage_path' => 'media/documents/conversions/missing-thumb.webp',
        'status' => 'available',
        'width' => 100,
        'height' => 100,
        'size' => 9,
        'format' => 'webp',
        'quality' => 80,
        'source_revision' => 1,
    ]);
    Storage::disk('public')->put($media->buildPath(), 'document');

    expect(fn () => app(RelocateMediaAction::class)->execute(
        $media,
        'archive',
        MediaVisibility::Public,
        expectedRevision: 1,
    ))->toThrow(MediaUploadException::class, 'source is missing');

    Storage::disk('archive')->assertMissing($media->buildPath());
    expect($media->refresh()->disk)->toBe('public');
});

it('projects nullable URLs only for media with an existing canonical binary', function (): void {
    $resolver = app(MediaUrlResolver::class);
    $media = g09Media(['is_public' => true]);

    expect($resolver->forExistingMedia(null))->toBeNull()
        ->and(MediaFacade::urlIfExists($media))->toBeNull();

    Storage::disk('public')->put($media->buildPath(), 'document');
    app(MediaFileExistence::class)->forget($media->disk, $media->buildPath());

    expect(MediaFacade::urlIfExists($media))->toBeString()->not->toBe('');
});

it('registers one named update route for both put and patch', function (): void {
    $route = app('router')->getRoutes()->getByName('nvl.media.management.update');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('PUT', 'PATCH')
        ->and(app('router')->getRoutes()->getByName('nvl.media.management.update.patch'))->toBeNull();
});

it('dry-runs and idempotently applies a reconciled Spatie-style adoption', function (): void {
    config(['media.root_folder' => '']);
    Schema::create('media_spatie_legacy', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('model_type')->nullable();
        $table->string('model_id')->nullable();
        $table->string('collection_name')->default('default');
        $table->string('name');
        $table->string('file_name');
        $table->string('mime_type');
        $table->string('disk');
        $table->unsignedBigInteger('size');
        $table->json('custom_properties')->nullable();
        $table->unsignedInteger('order_column')->default(0);
        $table->timestamps();
    });
    Schema::create('media_spatie_translations_legacy', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('media_id');
        $table->string('locale');
        $table->string('title')->nullable();
        $table->timestamps();
    });
    Schema::create('media_spatie_variations_legacy', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('media_id');
        $table->string('label');
        $table->string('storage_path');
        $table->unsignedInteger('width');
        $table->unsignedInteger('height');
        $table->unsignedBigInteger('size');
        $table->string('format');
        $table->unsignedTinyInteger('quality');
        $table->timestamps();
    });

    DB::table('media_spatie_legacy')->insert([
        'id' => 41,
        'model_type' => TestMediaModel::class,
        'model_id' => 'owner-41',
        'collection_name' => 'documents',
        'name' => 'Annual report',
        'file_name' => 'annual-report.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 6,
        'custom_properties' => json_encode(['source' => 'spatie'], JSON_THROW_ON_ERROR),
        'order_column' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('media_spatie_translations_legacy')->insert([
        'id' => 51,
        'media_id' => 41,
        'locale' => 'en',
        'title' => 'Annual report',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('media_spatie_variations_legacy')->insert([
        'id' => 61,
        'media_id' => 41,
        'label' => 'thumb',
        'storage_path' => '41/conversions/annual-report-thumb.webp',
        'width' => 100,
        'height' => 100,
        'size' => 5,
        'format' => 'webp',
        'quality' => 80,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Storage::disk('public')->put('41/annual-report.pdf', 'report');
    Storage::disk('public')->put('41/conversions/annual-report-thumb.webp', 'thumb');

    $action = app(AdoptSpatieMediaAction::class);
    $dryRun = $action->execute(
        'media_spatie_legacy',
        'media_spatie_translations_legacy',
        'media_spatie_variations_legacy',
    );

    expect($dryRun->mode)->toBe('dry-run')
        ->and($dryRun->ready)->toBeTrue()
        ->and($dryRun->sourceMedia)->toBe(1)
        ->and($dryRun->sourceAssociations)->toBe(1)
        ->and($dryRun->sourceTranslations)->toBe(1)
        ->and($dryRun->sourceVariations)->toBe(1)
        ->and($dryRun->matchedMedia)->toBe(0);

    $this->artisan('nvl:media:adopt-spatie', [
        '--source' => 'media_spatie_legacy',
        '--translations' => 'media_spatie_translations_legacy',
        '--variations' => 'media_spatie_variations_legacy',
        '--format' => 'json',
    ])->assertSuccessful();

    $applied = $action->execute(
        'media_spatie_legacy',
        'media_spatie_translations_legacy',
        'media_spatie_variations_legacy',
        apply: true,
    );
    $repeated = $action->execute(
        'media_spatie_legacy',
        'media_spatie_translations_legacy',
        'media_spatie_variations_legacy',
        apply: true,
    );

    expect($applied->matchedMedia)->toBe(1)
        ->and($applied->matchedAssociations)->toBe(1)
        ->and($applied->matchedTranslations)->toBe(1)
        ->and($applied->matchedVariations)->toBe(1)
        ->and($repeated->matchedMedia)->toBe(1)
        ->and(Media::query()->count())->toBe(1)
        ->and(MediaAssociation::query()->count())->toBe(1)
        ->and(MediaTranslation::query()->count())->toBe(1)
        ->and(MediaImageVariation::query()->count())->toBe(1)
        ->and(Schema::hasTable('media_spatie_legacy'))->toBeTrue();
});

it('registers the dry-run-first Spatie adoption command', function (): void {
    expect(Artisan::all())->toHaveKey('nvl:media:adopt-spatie');
});

it('diagnoses root-folder path drift against representative persisted rows', function (): void {
    $media = g09Media(['folder' => 'legacy', 'hash' => 'existing.pdf']);
    Storage::disk('public')->put('legacy/existing.pdf', 'existing');
    config(['media.root_folder' => 'media']);

    $drifted = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'storage.persisted_paths');

    expect($drifted)->not->toBeNull()
        ->and($drifted->passed)->toBeFalse()
        ->and($drifted->message)->toContain('empty media.root_folder');

    config(['media.root_folder' => '']);
    $aligned = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'storage.persisted_paths');

    expect($aligned)->not->toBeNull()
        ->and($aligned->passed)->toBeTrue()
        ->and($media->buildPath())->toBe('legacy/existing.pdf');
});
