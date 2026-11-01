<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\MediaInUseException;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaMultipart;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Services\MediaVariations;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Media\Tests\Stubs\TestMediaUser;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    config([
        'filesystems.disks.public-only' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/public-only')],
        'filesystems.disks.private-only' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/private-only')],
        'nvl-media.allowed_disks' => ['public-only', 'private-only'],
        'nvl-media.delivery.disks' => ['public-only' => ['private' => false], 'private-only' => ['public' => false]],
        'nvl-media.public_disk' => 'public-only',
        'nvl-media.private_disk' => 'private-only',
        'nvl-media.auto_generate_variations' => false,
        'nvl-media.multipart.enabled' => true,
    ]);
    Storage::fake('public-only');
    Storage::fake('private-only');
});

function deliveryWriteUpload(string $disk, bool $public): Media
{
    return app(MediaUploader::class)->upload(
        SystemActor::Background,
        UploadedFile::fake()->createWithContent('source.txt', 'delivery source bytes'),
        $disk,
        TestMediaModel::query()->create(['name' => 'Delivery owner']),
        new MediaSlot('documents'),
        'source.txt',
        isPublic: $public,
        skipAutoVariations: true,
    );
}

it('rejects native uploads before storing bytes when the disk excludes their visibility', function (string $disk, bool $public): void {
    expect(fn () => deliveryWriteUpload($disk, $public))->toThrow(RuntimeException::class, 'does not admit');
    expect(Media::query()->count())->toBe(0)->and(Storage::disk($disk)->allFiles())->toBe([]);
})->with(['private on public-only' => ['public-only', false], 'public on private-only' => ['private-only', true]]);

it('checks explicit visibility when resolving an allowed disk', function (string $disk, bool $public): void {
    expect(fn () => app(MediaDiskGuard::class)->resolveAllowed($disk, $public))->toThrow(RuntimeException::class, 'does not admit');
})->with(['private on public-only' => ['public-only', false], 'public on private-only' => ['private-only', true]]);

it('rejects multipart initiation before creating a receipt or contacting the provider', function (string $disk, MediaVisibility $visibility): void {
    $gateway = Mockery::mock(MultipartUploadGateway::class);
    $gateway->shouldNotReceive('initiate');
    app()->instance(MultipartUploadGateway::class, $gateway);
    $actor = TestMediaUser::query()->create(['name' => 'Multipart actor', 'email' => 'delivery@example.test', 'password' => 'unused']);
    expect(fn () => app(MediaMultipart::class)->initiate([
        'disk' => $disk,
        'filename' => 'source.txt',
        'mimeType' => 'text/plain',
        'size' => 6,
        'checksum' => hash('sha256', 'source'),
        'visibility' => $visibility,
    ], $actor))->toThrow(RuntimeException::class, 'does not admit');
    expect(MediaMultipartUpload::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk($disk)->allFiles())->toBe([]);
})->with(['private on public-only' => ['public-only', MediaVisibility::Private], 'public on private-only' => ['private-only', MediaVisibility::Public]]);

it('rejects relocation before copying objects to a disk that excludes the target visibility', function (bool $public): void {
    $sourceDisk = $public ? 'public-only' : 'private-only';
    $targetDisk = $public ? 'private-only' : 'public-only';
    $media = deliveryWriteUpload($sourceDisk, $public);
    $path = $media->buildPath();
    $revision = $media->revision;
    expect(fn () => app(MediaLibrary::class)->relocate(SystemActor::Background, $media, $targetDisk, $public ? MediaVisibility::Public : MediaVisibility::Private))->toThrow(RuntimeException::class, 'does not admit');
    expect($media->fresh()->disk)->toBe($sourceDisk)
        ->and($media->fresh()->revision)->toBe($revision)
        ->and(Storage::disk($sourceDisk)->get($path))->toBe('delivery source bytes')
        ->and(Storage::disk($targetDisk)->allFiles())->toBe([]);
})->with([true, false]);

it('accepts a metadata visibility no-op while updating other display fields', function (bool $public): void {
    $disk = $public ? 'public-only' : 'private-only';
    $media = deliveryWriteUpload($disk, $public);
    $updated = app(MediaLibrary::class)->updateMetadata(SystemActor::Background, $media, ['isPublic' => $public, 'tags' => ['reviewed']]);
    expect($updated->is_public)->toBe($public)
        ->and($updated->tags)->toBe(['reviewed'])
        ->and(Storage::disk($disk)->get($updated->buildPath()))->toBe('delivery source bytes');
})->with([true, false]);

it('requires verified relocation for a metadata visibility change without partially applying metadata', function (bool $public): void {
    $disk = $public ? 'public-only' : 'private-only';
    $media = deliveryWriteUpload($disk, $public);
    expect(fn () => app(MediaLibrary::class)->updateMetadata(SystemActor::Background, $media, ['isPublic' => ! $public, 'tags' => ['rejected']]))->toThrow(MediaUploadException::class, 'relocate');
    expect($media->fresh()->is_public)->toBe($public)
        ->and($media->fresh()->tags)->toBeNull()
        ->and(Storage::disk($disk)->get($media->fresh()->buildPath()))->toBe('delivery source bytes');
})->with([true, false]);

it('changes visibility through an admitted verified relocation and cleans the source on commit', function (): void {
    $media = deliveryWriteUpload('public-only', true);
    $sourcePath = $media->buildPath();
    DB::beginTransaction();
    $relocated = app(MediaLibrary::class)->relocate(SystemActor::Background, $media, 'private-only', MediaVisibility::Private, $media->revision);
    expect($relocated->disk)->toBe('private-only')
        ->and($relocated->is_public)->toBeFalse()
        ->and($relocated->revision)->toBe($media->revision + 1)
        ->and(Storage::disk('private-only')->get($relocated->buildPath()))->toBe('delivery source bytes');
    Storage::disk('public-only')->assertExists($sourcePath);
    DB::commit();
    Storage::disk('public-only')->assertMissing($sourcePath);
});

it('revalidates persisted visibility before replacement or move writes after policy changes', function (string $operation, bool $public): void {
    $disk = $public ? 'public-only' : 'private-only';
    $media = deliveryWriteUpload($disk, $public);
    $path = $media->buildPath();
    $revision = $media->revision;
    $folder = $media->folder;
    config(['nvl-media.delivery.disks.'.$disk.'.'.($public ? 'public' : 'private') => false]);
    expect(function () use ($media, $operation): void {
        if ($operation === 'replace') {
            app(MediaUploader::class)->replace(SystemActor::Background, $media, UploadedFile::fake()->createWithContent('replacement.txt', 'replacement bytes'));
        } else {
            app(MediaLibrary::class)->move(SystemActor::Background, [$media->id], 'moved');
        }
    })->toThrow(RuntimeException::class, 'does not admit');
    expect($media->fresh()->buildPath())->toBe($path)
        ->and($media->fresh()->revision)->toBe($revision)
        ->and($media->fresh()->folder)->toBe($folder)
        ->and(Storage::disk($disk)->allFiles())->toBe([$path])
        ->and(Storage::disk($disk)->get($path))->toBe('delivery source bytes');
})->with(['replace', 'move'])->with([true, false]);

it('refuses to make a shared public asset private through an otherwise admitted relocation', function (): void {
    $media = deliveryWriteUpload('public-only', true);
    $sourcePath = $media->buildPath();
    $revision = $media->revision;
    foreach (['First owner', 'Second owner'] as $name) {
        app(MediaAttachments::class)->attach(SystemActor::Background, $media, TestMediaModel::query()->create(['name' => $name]), dispatchVariations: false);
    }
    expect(fn () => app(MediaLibrary::class)->relocate(SystemActor::Background, $media, 'private-only', MediaVisibility::Private, $revision))->toThrow(MediaInUseException::class);
    $fresh = $media->fresh();
    expect($fresh->disk)->toBe('public-only')
        ->and($fresh->visibility)->toBe(MediaVisibility::Public)
        ->and($fresh->revision)->toBe($revision)
        ->and($fresh->associations()->count())->toBe(2)
        ->and(Storage::disk('public-only')->get($sourcePath))->toBe('delivery source bytes')
        ->and(Storage::disk('private-only')->allFiles())->toBe([]);
});

it('rejects variation output after the persisted visibility is disabled without changing source state', function (): void {
    $media = app(MediaUploader::class)->upload(SystemActor::Background, UploadedFile::fake()->image('source.png', 32, 32), 'private-only', TestMediaModel::query()->create(['name' => 'Image owner']), new MediaSlot('documents'), 'source.png', skipAutoVariations: true);
    $sourcePath = $media->buildPath();
    $sourceBytes = Storage::disk('private-only')->get($sourcePath);
    config(['nvl-media.delivery.disks.private-only.private' => false]);
    expect(fn () => app(MediaVariations::class)->generate(SystemActor::Background, $media, ConversionDefinition::resize('thumb', 16, 16)))->toThrow(RuntimeException::class, 'does not admit');
    expect($media->fresh()->status)->toBe(MediaLifecycleStatus::Available)
        ->and($media->fresh()->revision)->toBe($media->revision)
        ->and($media->imageVariations()->count())->toBe(0)
        ->and(Storage::disk('private-only')->allFiles())->toBe([$sourcePath])
        ->and(Storage::disk('private-only')->get($sourcePath))->toBe($sourceBytes);
});
