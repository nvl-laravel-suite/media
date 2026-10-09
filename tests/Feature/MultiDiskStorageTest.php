<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\RelocateMediaAction;
use Nvl\Media\Actions\ReplaceMediaFileAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaDoctor;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Stubs\TestMediaModel;

beforeEach(function (): void {
    config([
        'filesystems.disks.private' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/private'),
            'throw' => true,
        ],
        'filesystems.disks.archive' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/archive'),
            'throw' => true,
        ],
        'nvl-media.allowed_disks' => ['public', 'private', 'archive'],
        'nvl-media.root_folder' => 'fallback',
        'nvl-media.root_folders' => ['public' => 'media', 'private' => '', 'archive' => 'assets/uploads'],
        'nvl-media.public_disk' => 'public',
        'nvl-media.private_disk' => 'private',
        'nvl-media.auto_generate_variations' => false,
        'nvl-media.output_conversion.enabled' => false,
    ]);

    foreach (['public', 'private', 'archive'] as $disk) {
        Storage::fake($disk);
    }
});

function multiDiskUpload(string $disk, string $folder = ''): Media
{
    $owner = TestMediaModel::create(['name' => 'Multiple disks owner']);

    return app(UploadMediaAction::class)->execute(
        UploadedFile::fake()->createWithContent('original.txt', 'original disk bytes'),
        $disk,
        $owner,
        new MediaSlot('documents'),
        'original.txt',
        folderOverride: $folder,
        skipAutoVariations: true,
    );
}

it('stores originals in the selected disk folder including directly at its root', function (string $disk, string $folder, string $prefix): void {
    $media = multiDiskUpload($disk, $folder);
    $expected = $prefix.$media->hash;

    expect($media->buildPath())->toBe($expected)
        ->and(Storage::disk($disk)->get($expected))->toBe('original disk bytes');
})->with([
    'public media folder' => ['public', '', 'media/'],
    'private disk root' => ['private', '', ''],
    'private disk subfolder' => ['private', 'documents', 'documents/'],
    'nested custom root' => ['archive', 'invoices', 'assets/uploads/invoices/'],
]);

it('selects a configured visibility disk for fluent uploads', function (bool $public, string $disk, string $prefix): void {
    $owner = TestMediaModel::create(['name' => 'Visibility owner']);
    $media = $owner->nvlMedia()->add(UploadedFile::fake()->createWithContent('upload.txt', 'visibility bytes'))
        ->asPublic($public)->toFolder('')->withoutVariations()->upload();

    expect($media->disk)->toBe($disk)
        ->and($media->buildPath())->toBe($prefix.$media->hash);
    Storage::disk($disk)->assertExists($prefix.$media->hash);
})->with([
    'public' => [true, 'public', 'media/'],
    'private' => [false, 'private', ''],
]);

it('keeps an explicit upload disk ahead of visibility defaults', function (): void {
    $owner = TestMediaModel::create(['name' => 'Explicit disk owner']);
    $media = $owner->nvlMedia()->add(UploadedFile::fake()->createWithContent('upload.txt', 'explicit bytes'))
        ->toDisk('archive')->asPublic()->toFolder('')->withoutVariations()->upload();

    expect($media->disk)->toBe('archive');
    Storage::disk('archive')->assertExists('assets/uploads/'.$media->hash);
});

it('honors an empty default folder for root-level fluent uploads', function (): void {
    config(['nvl-media.default_path' => '']);
    $owner = TestMediaModel::create(['name' => 'Root default owner']);
    $media = $owner->nvlMedia()->add(UploadedFile::fake()->createWithContent('upload.txt', 'root default bytes'))
        ->asPublic(false)->withoutVariations()->upload();

    expect($media->folder)->toBe('')
        ->and($media->buildPath())->toBe($media->hash);
    Storage::disk('private')->assertExists($media->hash);
});

it('replaces a root-level original without introducing a global folder', function (): void {
    $media = multiDiskUpload('private');
    $oldPath = $media->hash;
    $updated = app(ReplaceMediaFileAction::class)->execute(
        $media, UploadedFile::fake()->createWithContent('replacement.txt', 'replacement bytes'),
    );

    expect($updated->buildPath())->toBe($updated->hash)
        ->and(Storage::disk('private')->get($updated->hash))->toBe('replacement bytes');
    DB::connection()->commit();
    Storage::disk('private')->assertMissing($oldPath);
});

it('relocates originals and variations into the destination disk folder', function (): void {
    $media = multiDiskUpload('public', 'documents');
    $oldOriginal = 'media/documents/'.$media->hash;
    $oldVariation = 'media/documents/conversions/preview.webp';
    $variation = MediaImageVariation::factory()->create([
        'media_id' => $media->id, 'storage_path' => $oldVariation, 'source_revision' => $media->revision,
    ]);
    Storage::disk('public')->put($oldVariation, 'preview bytes');

    $relocated = app(RelocateMediaAction::class)->execute($media, 'private', MediaVisibility::Private);

    expect($relocated->buildPath())->toBe('documents/'.$media->hash)
        ->and($variation->fresh()?->getPath())->toBe('documents/conversions/preview.webp');
    Storage::disk('private')->assertExists(['documents/'.$media->hash, 'documents/conversions/preview.webp']);
    DB::connection()->commit();
    Storage::disk('public')->assertMissing([$oldOriginal, $oldVariation]);
});

it('retains source objects when relocation to a different folder fails', function (): void {
    $media = multiDiskUpload('public', 'documents');
    MediaImageVariation::factory()->create([
        'media_id' => $media->id, 'storage_path' => 'media/documents/conversions/missing.webp',
    ]);

    expect(fn () => app(RelocateMediaAction::class)->execute($media, 'private', MediaVisibility::Private))
        ->toThrow(MediaUploadException::class, 'source is missing');
    Storage::disk('public')->assertExists('media/documents/'.$media->hash);
    Storage::disk('private')->assertMissing('documents/'.$media->hash);
    expect($media->fresh()?->disk)->toBe('public');
});

it('migrates disks using the destination folder and keeps media paths consistent', function (): void {
    $media = multiDiskUpload('public', 'documents');
    $variation = MediaImageVariation::factory()->create([
        'media_id' => $media->id, 'storage_path' => 'media/documents/conversions/preview.webp',
    ]);
    Storage::disk('public')->put('media/documents/conversions/preview.webp', 'preview bytes');

    $this->artisan('nvl:media:migrate-disk', ['--from' => 'public', '--to' => 'archive', '--no-interaction' => true])
        ->assertSuccessful();

    expect($media->fresh()?->buildPath())->toBe('assets/uploads/documents/'.$media->hash);
    expect($variation->fresh()?->getPath())->toBe('assets/uploads/documents/conversions/preview.webp');
    Storage::disk('archive')->assertExists(['assets/uploads/documents/'.$media->hash, 'assets/uploads/documents/conversions/preview.webp']);
    Storage::disk('public')->assertMissing('media/documents/'.$media->hash);
});

it('rolls failed disk migrations back across different root folders', function (): void {
    $media = multiDiskUpload('public', 'documents');
    MediaImageVariation::factory()->create([
        'media_id' => $media->id, 'storage_path' => 'media/documents/conversions/missing.webp',
    ]);

    $this->artisan('nvl:media:migrate-disk', ['--from' => 'public', '--to' => 'archive', '--no-interaction' => true])->assertFailed();

    expect($media->fresh()?->disk)->toBe('public');
    Storage::disk('public')->assertExists('media/documents/'.$media->hash);
    Storage::disk('archive')->assertMissing('assets/uploads/documents/'.$media->hash);
});

it('reconciles only the selected disk prefix', function (): void {
    Storage::disk('archive')->put('assets/uploads/orphan.txt', 'old orphan');
    Storage::disk('archive')->put('elsewhere/host.txt', 'host bytes');

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'archive', '--orphans' => true, '--cleanup-orphans' => true, '--older-than' => 0,
    ])->assertSuccessful();

    Storage::disk('archive')->assertMissing('assets/uploads/orphan.txt');
    Storage::disk('archive')->assertExists('elsewhere/host.txt');
});

it('refuses whole-disk orphan cleanup when the selected disk has no folder prefix', function (): void {
    Storage::disk('private')->put('host.txt', 'unrelated host bytes');

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'private', '--orphans' => true, '--cleanup-orphans' => true, '--older-than' => 0,
    ])->assertFailed();

    Storage::disk('private')->assertExists('host.txt');
});

it('builds legacy variation paths under the parent disk root without a leading dot', function (): void {
    $media = multiDiskUpload('private');
    $variation = MediaImageVariation::factory()->create([
        'media_id' => $media->id, 'storage_path' => null, 'label' => 'thumb',
        'width' => 100, 'height' => 100, 'format' => 'webp',
    ]);

    expect($variation->getPath())->toStartWith('conversions/')
        ->not->toStartWith('./')
        ->not->toContain('fallback/');
});

it('reports valid multi-disk configuration and rejects an undefined visibility disk', function (): void {
    $valid = collect(app(MediaDoctor::class)->inspect())->firstWhere('key', 'storage.configuration');
    expect($valid?->passed)->toBeTrue();
    config(['nvl-media.private_disk' => 'undefined-disk']);
    $invalid = collect(app(MediaDoctor::class)->inspect())->firstWhere('key', 'storage.configuration');
    expect($invalid?->passed)->toBeFalse();
});

it('reports invalid roots for existing records without crashing Doctor', function (): void {
    multiDiskUpload('public');
    config(['nvl-media.root_folders.public' => null]);

    $checks = collect(app(MediaDoctor::class)->inspect());
    expect($checks->firstWhere('key', 'storage.configuration')?->passed)->toBeFalse()
        ->and($checks->firstWhere('key', 'storage.persisted_paths')?->passed)->toBeFalse();
});

it('rejects dot-root cleanup without deleting unrelated host objects', function (): void {
    config(['nvl-media.root_folders.private' => '.']);
    Storage::disk('private')->put('host.txt', 'host bytes');

    expect(fn (): int => $this->artisan('nvl:media:reconcile', [
        '--disk' => 'private', '--orphans' => true, '--cleanup-orphans' => true, '--older-than' => 0,
    ])->run())->toThrow(MediaUploadException::class);
    Storage::disk('private')->assertExists('host.txt');
});
