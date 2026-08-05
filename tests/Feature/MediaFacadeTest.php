<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Contracts\UploadMediaContract;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Facades\Media as MediaFacade;
use Nvl\Media\MediaLibrary;
use Nvl\Media\Models\Media;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Media\Tests\Stubs\TestPermissionMediaUser;

beforeEach(function (): void {
    if (! Schema::hasTable('test_media_models')) {
        Schema::create('test_media_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    MediaFacade::clearResolvedInstance(MediaLibraryContract::class);
});

test('the facade resolves the replaceable media library contract', function (): void {
    $library = app(MediaLibraryContract::class);

    expect($library)->toBeInstanceOf(MediaLibrary::class)
        ->and(MediaFacade::getFacadeRoot())->toBe($library);
});

test('facade model uploads use the same replaceable action contract as the trait', function (): void {
    $owner = TestMediaModel::query()->create(['name' => 'Facade owner']);
    $resolvedMedia = Media::query()->create([
        'filename' => 'contract.txt',
        'hash' => 'contract.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => 16,
        'disk' => 'public',
        'folder' => 'contract',
        'is_public' => false,
        'type' => MediaType::DOCUMENT,
        'digest' => hash('sha256', 'contract-result'),
    ]);
    $uploads = Mockery::mock(UploadMediaContract::class);
    $uploads->shouldReceive('execute')
        ->once()
        ->andReturn($resolvedMedia);
    app()->instance(UploadMediaContract::class, $uploads);

    $uploaded = MediaFacade::add(
        $owner,
        UploadedFile::fake()->createWithContent('input.txt', 'contract-input'),
        'documents',
    )
        ->withoutVariations()
        ->upload();

    expect($uploaded->is($resolvedMedia))->toBeTrue()
        ->and($owner->getFirstMedia('documents')?->is($resolvedMedia))->toBeTrue();
});

test('the facade supports typed reads and convenient array filters', function (): void {
    Media::query()->create([
        'filename' => 'facade.txt',
        'hash' => 'facade.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => 6,
        'disk' => 'public',
        'is_public' => true,
        'type' => MediaType::DOCUMENT,
        'digest' => hash('sha256', 'facade'),
    ]);
    Media::query()->create([
        'filename' => 'facade.pdf',
        'hash' => 'facade.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size' => 6,
        'disk' => 'public',
        'is_public' => true,
        'type' => MediaType::DOCUMENT,
        'digest' => hash('sha256', 'facade-pdf'),
    ]);

    $page = MediaFacade::paginate([
        'extension' => 'txt',
        'perPage' => 1000,
    ]);

    expect($page->total())->toBe(1)
        ->and($page->perPage())->toBe(100)
        ->and($page->items()[0]->filename)->toBe('facade.txt')
        ->and(MediaFacade::findOrFail($page->items()[0]->id)->filename)->toBe('facade.txt');
});

test('facade authorization uses the same optional global-role bridge as policies and queries', function (): void {
    config()->set('media.authorization.spatie_permission.global_roles', ['admin']);

    $admin = TestPermissionMediaUser::withoutEvents(
        static fn (): TestPermissionMediaUser => TestPermissionMediaUser::forceCreate([
            'name' => 'Media admin',
            'email' => 'media-admin@example.test',
            'password' => 'secret',
        ]),
    )->withMediaRoles(['admin']);
    $media = Media::query()->create([
        'filename' => 'private.txt',
        'hash' => 'private.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => 7,
        'disk' => 'public',
        'is_public' => false,
        'type' => MediaType::DOCUMENT,
        'digest' => hash('sha256', 'private'),
        'uploaded_by' => 'another-owner',
        'uploaded_by_type' => 'users',
    ]);

    expect(MediaFacade::allows($admin, MediaAbility::Delete, $media))->toBeTrue();
});

test('applications may replace the complete facade boundary', function (): void {
    $media = Media::query()->create([
        'filename' => 'replacement.txt',
        'hash' => 'replacement.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => 11,
        'disk' => 'public',
        'is_public' => true,
        'type' => MediaType::DOCUMENT,
        'digest' => hash('sha256', 'replacement'),
    ]);
    $library = Mockery::mock(MediaLibraryContract::class);
    $library->shouldReceive('findOrFail')
        ->once()
        ->with($media->id)
        ->andReturn($media);
    app()->instance(MediaLibraryContract::class, $library);
    MediaFacade::clearResolvedInstance(MediaLibraryContract::class);

    expect(MediaFacade::findOrFail($media->id))->toBe($media);
});
