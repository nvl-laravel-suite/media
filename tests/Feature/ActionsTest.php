<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Actions\DetachMediaAction;
use Nvl\Media\Actions\ReplaceMediaFileAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Events\MediaUploadedEvent;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaInUseException;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Stubs\RestrictedMediaModel;

function createMediaRecord(array $overrides = []): Media
{
    return Media::create(array_merge([
        'filename' => 'test-image.jpg',
        'hash' => md5(uniqid('', true)).'.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'disk' => 'public',
        'folder' => 'test',
        'is_public' => true,
        'type' => MediaType::IMAGE,
        'digest' => md5('test'),
        'tags' => ['hero', 'banner'],
        'metadata' => ['source' => 'upload'],
    ], $overrides));
}

function createTestUser(array $overrides = []): User
{
    return User::withoutEvents(
        static fn (): User => User::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'yIXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',

        ], $overrides)),
    );
}

function failActionPublicDiskDeletes(bool $throwOnDelete): void
{
    $disk = Storage::disk('public');
    $failingDisk = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig(), $throwOnDelete) extends FilesystemAdapter
    {
        public function __construct(
            FilesystemOperator $driver,
            FlysystemAdapter $adapter,
            array $config,
            private readonly bool $throwOnDelete,
        ) {
            parent::__construct($driver, $adapter, $config);
        }

        public function delete($paths)
        {
            if ($this->throwOnDelete) {
                throw new RuntimeException('Injected upload cleanup failure.');
            }

            return false;
        }
    };

    Storage::set('public', $failingDisk);
}

/* =================================================================
 * UploadMediaAction
 * ================================================================= */

describe('UploadMediaAction', function () {

    beforeEach(function () {
        Storage::fake('public');

        config([
            'filesystems.default' => 'public',
            'media.default_path' => '{model_type}/{model_id}',
            'media.group_types' => [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'document' => ['pdf', 'doc', 'docx'],
                'video' => ['mp4', 'mpeg', 'webm', 'mov'],
                'audio' => ['mp3', 'wav', 'ogg'],
                'archive' => ['zip', 'rar'],
                'code' => ['json', 'xml'],
            ],
        ]);
    });

    it('uploads a file and creates media record', function () {
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $user = createTestUser();
        $slot = new MediaSlot('gallery');

        $action = app(UploadMediaAction::class);

        $media = $action->execute(
            file: $file,
            disk: 'public',
            model: $user,
            slot: $slot,
            fileName: 'photo.jpg',
            isPublic: true,
            tags: ['test'],
        );

        expect($media)->toBeInstanceOf(Media::class)
            ->and($media->exists)->toBeTrue()
            ->and($media->filename)->toBe('photo.jpg')
            ->and($media->extension)->toBe('jpg')
            ->and($media->mime_type)->toBe('image/jpeg')
            ->and($media->disk)->toBe('public')
            ->and($media->is_public)->toBeTrue()
            ->and($media->type)->toBe(MediaType::IMAGE)
            ->and($media->tags)->toBe(['test'])
            ->and($media->size)->toBeGreaterThan(0);

        // Assert file stored on disk
        Storage::disk('public')->assertExists($media->buildPath());
    });

    it('preserves the upload failure when stored-object cleanup cannot complete', function (bool $throwOnDelete) {
        $user = createTestUser();
        failActionPublicDiskDeletes($throwOnDelete);
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('upload transaction failed'));

        expect(fn () => app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->createWithContent(
                'cleanup-primary.txt',
                'stored-before-database-failure',
            ),
            disk: 'public',
            model: $user,
            slot: new MediaSlot('documents'),
            fileName: 'cleanup-primary.txt',
            skipAutoVariations: true,
        ))->toThrow(RuntimeException::class, 'upload transaction failed');

        expect(Storage::disk('public')->allFiles())->toHaveCount(1);
    })->with([
        'cleanup returns false' => false,
        'cleanup throws' => true,
    ]);

    it('records a polymorphic uploader for private ownership boundaries', function () {
        $user = createTestUser();
        $this->actingAs($user);

        $media = app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->image('private.jpg', 100, 100),
            disk: 'public',
            model: $user,
            slot: new MediaSlot('documents'),
            fileName: 'private.jpg',
        );

        expect($media->uploaded_by)->toBe((string) $user->getAuthIdentifier())
            ->and($media->uploaded_by_type)->toBe($user->getMorphClass())
            ->and($media->uploader->is($user))->toBeTrue();
    });

    it('dispatches the upload event when a deduplicated upload creates its media row', function () {
        Event::fake([MediaUploadedEvent::class]);

        $user = createTestUser();

        $media = app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->createWithContent('public.txt', 'public-asset'),
            disk: 'public',
            model: $user,
            slot: (new MediaSlot('library'))->publicReusable(),
            fileName: 'public.txt',
            isPublic: true,
        );

        Event::assertDispatched(
            MediaUploadedEvent::class,
            static fn (MediaUploadedEvent $event): bool => $event->media->is($media),
        );
    });

    it('skips deduplication candidates whose authoritative object is missing', function () {
        config([
            'media.cache_file_existence' => true,
            'media.cache_ttl' => 60,
        ]);

        $user = createTestUser();
        $slot = (new MediaSlot('library'))->publicReusable();
        $action = app(UploadMediaAction::class);
        $first = $action->execute(
            file: UploadedFile::fake()->createWithContent('shared.txt', 'shared-content'),
            disk: 'public',
            model: $user,
            slot: $slot,
            fileName: 'shared.txt',
            isPublic: true,
            skipAutoVariations: true,
        );

        expect(app(MediaFileExistence::class)->exists('public', $first->buildPath()))
            ->toBeTrue();
        Storage::disk('public')->delete($first->buildPath());

        $replacement = $action->execute(
            file: UploadedFile::fake()->createWithContent('shared.txt', 'shared-content'),
            disk: 'public',
            model: $user,
            slot: $slot,
            fileName: 'shared.txt',
            isPublic: true,
            skipAutoVariations: true,
        );
        $reused = $action->execute(
            file: UploadedFile::fake()->createWithContent('shared.txt', 'shared-content'),
            disk: 'public',
            model: $user,
            slot: $slot,
            fileName: 'shared.txt',
            isPublic: true,
            skipAutoVariations: true,
        );

        expect($replacement->id)->not->toBe($first->id)
            ->and($reused->id)->toBe($replacement->id)
            ->and(Media::query()->where('digest', $first->digest)->count())->toBe(2);
        Storage::disk('public')->assertExists($replacement->buildPath());
    });

    it('generates unique hash for filename', function () {
        $file1 = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $file2 = UploadedFile::fake()->image('photo.jpg', 200, 200);
        $user = createTestUser();
        $slot = new MediaSlot('gallery');

        $action = app(UploadMediaAction::class);

        $media1 = $action->execute(
            file: $file1,
            disk: 'public',
            model: $user,
            slot: $slot,
            fileName: 'photo.jpg',
        );

        $media2 = $action->execute(
            file: $file2,
            disk: 'public',
            model: $user,
            slot: $slot,
            fileName: 'photo.jpg',
        );

        expect($media1->hash)->not->toBe($media2->hash);
    });

    it('determines correct MediaType from extension', function () {
        $jpg_file = UploadedFile::fake()->image('image.jpg', 50, 50);
        $user = createTestUser();
        $slot = new MediaSlot('files');
        $action = app(UploadMediaAction::class);

        $media = $action->execute(
            file: $jpg_file,
            disk: 'public',
            model: $user,
            slot: $slot,
            fileName: 'image.jpg',
        );

        expect($media->type)->toBe(MediaType::IMAGE);
    });

    it('enforces slot constraints for direct action callers before storage', function () {
        $slot = (new MediaSlot('documents'))
            ->acceptsMimeTypes(['application/pdf'])
            ->maxFileSize(1024);

        app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->image('photo.jpg', 100, 100),
            disk: 'public',
            model: createTestUser(),
            slot: $slot,
            fileName: 'photo.jpg',
        );
    })->throws(FileUnacceptableForCollection::class);

    it('enforces the disk allowlist for direct action callers before storage', function () {
        config([
            'filesystems.disks.untrusted' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/untrusted'),
            ],
            'media.allowed_disks' => ['public'],
        ]);

        app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->image('photo.jpg', 100, 100),
            disk: 'untrusted',
            model: createTestUser(),
            slot: new MediaSlot('gallery'),
            fileName: 'photo.jpg',
        );
    })->throws(ValidationException::class, 'not allowed for media operations');

    it('rejects traversal in caller-provided upload folders before storage', function () {
        app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->image('photo.jpg', 100, 100),
            disk: 'public',
            model: createTestUser(),
            slot: new MediaSlot('gallery'),
            fileName: 'photo.jpg',
            folderOverride: '..\\outside',
        );
    })->throws(MediaUploadException::class, 'Path traversal detected');

    it('rejects caller-provided display extensions that disagree with detected content', function () {
        app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->image('photo.jpg', 100, 100),
            disk: 'public',
            model: createTestUser(),
            slot: new MediaSlot('gallery'),
            fileName: 'unsafe/name.png',
        );
    })->throws(
        FileUnacceptableForCollection::class,
        'Detected MIME type [image/jpeg] does not match file extension [png].',
    );

    it('rejects executable extensions even when their detected MIME looks harmless', function () {
        app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->createWithContent('shell.php', 'plain text'),
            disk: 'public',
            model: createTestUser(),
            slot: new MediaSlot('documents'),
            fileName: 'shell.php',
        );
    })->throws(FileUnacceptableForCollection::class, 'extension [php] is forbidden');

    it('rejects dangerous segments anywhere in a multi-extension filename', function () {
        app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->image('photo.jpg', 100, 100),
            disk: 'public',
            model: createTestUser(),
            slot: new MediaSlot('gallery'),
            fileName: 'avatar.php.jpg',
        );
    })->throws(FileUnacceptableForCollection::class, 'extension [php] is forbidden');

    it('rejects zero-byte direct action uploads', function () {
        app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->createWithContent('empty.txt', ''),
            disk: 'public',
            model: createTestUser(),
            slot: new MediaSlot('documents'),
            fileName: 'empty.txt',
        );
    })->throws(FileUnacceptableForCollection::class);

    it('accepts configured MIME aliases for a canonical extension', function () {
        config(['media.file_types.csv' => ['text/csv', 'text/plain']]);

        $media = app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->createWithContent('records.csv', "name\nAda\n"),
            disk: 'public',
            model: createTestUser(),
            slot: new MediaSlot('documents'),
            fileName: 'records.csv',
        );

        expect($media->extension)->toBe('csv')
            ->and($media->mime_type)->toBe('text/plain');
    });

    it('infers a canonical configured extension when a display filename has none', function () {
        $media = app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->image('photo.jpg', 100, 100),
            disk: 'public',
            model: createTestUser(),
            slot: new MediaSlot('gallery'),
            fileName: 'safe-display-name',
        );

        expect($media->filename)->toBe('safe-display-name.jpg')
            ->and($media->hash)->toEndWith('.jpg');
    });

    it('runs the configured content scanner before any storage mutation', function () {
        app()->instance(MediaContentScanner::class, new class implements MediaContentScanner
        {
            public function scan(UploadedFile $file): void
            {
                throw new MediaUploadException('Malware signature detected.');
            }
        });

        app(UploadMediaAction::class)->execute(
            file: UploadedFile::fake()->createWithContent('unsafe.txt', 'unsafe'),
            disk: 'public',
            model: createTestUser(),
            slot: new MediaSlot('documents'),
            fileName: 'unsafe.txt',
        );
    })->throws(MediaUploadException::class, 'Malware signature detected');

    it('stores file on the specified disk', function () {
        Storage::fake('s3');
        config([
            'filesystems.disks.s3' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/s3')],
            'media.allowed_disks' => ['public', 's3'],
        ]);

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $user = createTestUser();
        $slot = new MediaSlot('gallery');

        $action = app(UploadMediaAction::class);

        $media = $action->execute(
            file: $file,
            disk: 's3',
            model: $user,
            slot: $slot,
            fileName: 'photo.jpg',
        );

        expect($media->disk)->toBe('s3');
        Storage::disk('s3')->assertExists($media->buildPath());
    });

    it('stores file on the cloudflare-r2 disk when explicitly selected', function () {
        Storage::fake('cloudflare-r2');
        config([
            'filesystems.disks.cloudflare-r2.driver' => 's3',
            'media.allowed_disks' => ['public', 'cloudflare-r2'],
        ]);

        $file = UploadedFile::fake()->image('r2-photo.jpg', 100, 100);
        $user = createTestUser();
        $slot = new MediaSlot('gallery');

        $action = app(UploadMediaAction::class);

        $media = $action->execute(
            file: $file,
            disk: 'cloudflare-r2',
            model: $user,
            slot: $slot,
            fileName: 'r2-photo.jpg',
            isPublic: true,
        );

        expect($media->disk)->toBe('cloudflare-r2')
            ->and($media->is_public)->toBeTrue();

        Storage::disk('cloudflare-r2')->assertExists($media->buildPath());
    });

    it('wraps everything in a transaction', function () {
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $user = createTestUser();
        $slot = new MediaSlot('gallery');

        $action = app(UploadMediaAction::class);
        $brokenRoot = storage_path('framework/testing/disks/broken-root-'.uniqid());
        file_put_contents($brokenRoot, 'not a directory');

        config([
            'filesystems.disks.broken-upload' => [
                'driver' => 'local',
                'root' => $brokenRoot,
            ],
            'media.allowed_disks' => ['public', 'broken-upload'],
        ]);

        try {
            $action->execute(
                file: $file,
                disk: 'broken-upload',
                model: $user,
                slot: $slot,
                fileName: 'photo.jpg',
            );
        } catch (Throwable) {
            // Expected
        } finally {
            @unlink($brokenRoot);
        }

        expect(Media::count())->toBe(0);
    });
});

/* =================================================================
 * AttachMediaAction
 * ================================================================= */

describe('AttachMediaAction', function () {

    it('creates an association between media and model', function () {
        $media = createMediaRecord();
        $user = createTestUser();

        $action = app(AttachMediaAction::class);

        $association = $action->execute(
            media: $media,
            model: $user,
            collection: 'avatar',
        );

        expect($association)->toBeInstanceOf(MediaAssociation::class)
            ->and($association->exists)->toBeTrue()
            ->and($association->media_id)->toBe($media->id)
            ->and($association->associable_type)->toBe(User::class)
            ->and($association->associable_id)->toBe($user->id)
            ->and($association->collection)->toBe('avatar');

        $this->assertDatabaseHas(MediaTables::MEDIA_ASSOCIATIONS, [
            'media_id' => $media->id,
            'associable_type' => User::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
        ]);
    });

    it('deduplicates by composite key with firstOrCreate', function () {
        $media = createMediaRecord();
        $user = createTestUser();

        $action = app(AttachMediaAction::class);

        $assoc1 = $action->execute(media: $media, model: $user, collection: 'avatar');
        $assoc2 = $action->execute(media: $media, model: $user, collection: 'avatar');

        expect($assoc1->id)->toBe($assoc2->id);
        expect(MediaAssociation::where('media_id', $media->id)
            ->where('collection', 'avatar')
            ->count())->toBe(1);
    });

    it('sets locale and order', function () {
        $media = createMediaRecord();
        $user = createTestUser();

        $action = app(AttachMediaAction::class);

        $association = $action->execute(
            media: $media,
            model: $user,
            collection: 'gallery',
            locale: 'en',
            order: 5,
        );

        expect($association->locale)->toBe('en')
            ->and($association->order)->toBe(5);
    });

    it('stores metadata on the association', function () {
        $media = createMediaRecord();
        $user = createTestUser();

        $action = app(AttachMediaAction::class);

        $association = $action->execute(
            media: $media,
            model: $user,
            collection: 'docs',
            metadata: ['role' => 'primary', 'display' => true],
        );

        expect($association->metadata)->toBe(['role' => 'primary', 'display' => true]);
    });
});

describe('ReplaceMediaFileAction', function () {

    beforeEach(function () {
        Storage::fake('public');
        Storage::fake('s3');
        config([
            'filesystems.disks.s3' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/s3'),
            ],
            'media.allowed_disks' => ['public', 's3'],
            'media.auto_generate_variations' => false,
        ]);
    });

    it('enforces every persisted association slot before staging a replacement', function () {
        $owner = RestrictedMediaModel::query()->create(['name' => 'Restricted owner']);
        $contents = 'original';
        $media = createMediaRecord([
            'filename' => 'original.txt',
            'hash' => 'original.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => strlen($contents),
            'type' => MediaType::DOCUMENT,
            'digest' => hash('sha256', $contents),
        ]);
        Storage::disk('public')->put($media->buildPath(), $contents);
        app(AttachMediaAction::class)->execute(
            media: $media,
            model: $owner,
            collection: 'documents',
            metadata: ['slot' => 'documents'],
            dispatchVariations: false,
        );

        expect(fn () => app(ReplaceMediaFileAction::class)->execute(
            $media,
            UploadedFile::fake()->image('replacement.jpg', 100, 100),
        ))->toThrow(FileUnacceptableForCollection::class, 'not accepted by slot [documents]');

        expect($media->fresh()?->hash)->toBe('original.txt');
        Storage::disk('public')->assertExists($media->buildPath());
    });

    it('reloads moved media state before staging a replacement from a stale model', function () {
        $staleContents = 'stale-source';
        $currentContents = 'current-source';
        $staleMedia = createMediaRecord([
            'filename' => 'stale.txt',
            'hash' => 'stale-object.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => strlen($staleContents),
            'disk' => 'public',
            'folder' => 'stale-folder',
            'type' => MediaType::DOCUMENT,
            'digest' => hash('sha256', $staleContents),
            'revision' => 3,
        ]);
        $stalePath = $staleMedia->buildPath();
        Storage::disk('public')->put($stalePath, $staleContents);

        $currentMedia = Media::query()->findOrFail($staleMedia->id);
        $currentMedia->forceFill([
            'filename' => 'current.txt',
            'hash' => 'current-object.txt',
            'size' => strlen($currentContents),
            'disk' => 's3',
            'folder' => 'current-folder',
            'digest' => hash('sha256', $currentContents),
            'revision' => 7,
        ])->save();
        $currentPath = $currentMedia->buildPath();
        Storage::disk('s3')->put($currentPath, $currentContents);

        $replacement = app(ReplaceMediaFileAction::class)->execute(
            $staleMedia,
            UploadedFile::fake()->createWithContent('replacement.txt', 'replacement-content'),
        );

        expect($replacement->disk)->toBe('s3')
            ->and($replacement->folder)->toBe('current-folder')
            ->and($replacement->revision)->toBe(8)
            ->and(Storage::disk('s3')->get($replacement->buildPath()))->toBe('replacement-content')
            ->and(Storage::disk('public')->allFiles())->toBe([$stalePath]);

        Storage::disk('s3')->assertMissing($currentPath);
        Storage::disk('public')->assertExists($stalePath);
    });

    it('rolls a stale-model replacement back on the authoritative disk', function () {
        $staleContents = 'stale-source';
        $currentContents = 'current-source';
        $staleMedia = createMediaRecord([
            'filename' => 'stale.txt',
            'hash' => 'stale-object.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => strlen($staleContents),
            'disk' => 'public',
            'folder' => 'stale-folder',
            'type' => MediaType::DOCUMENT,
            'digest' => hash('sha256', $staleContents),
            'revision' => 4,
        ]);
        $stalePath = $staleMedia->buildPath();
        Storage::disk('public')->put($stalePath, $staleContents);

        $currentMedia = Media::query()->findOrFail($staleMedia->id);
        $currentMedia->forceFill([
            'filename' => 'current.txt',
            'hash' => 'current-object.txt',
            'size' => strlen($currentContents),
            'disk' => 's3',
            'folder' => 'current-folder',
            'digest' => hash('sha256', $currentContents),
            'revision' => 9,
        ])->save();
        $currentPath = $currentMedia->buildPath();
        Storage::disk('s3')->put($currentPath, $currentContents);

        DB::beginTransaction();

        try {
            $replacement = app(ReplaceMediaFileAction::class)->execute(
                $staleMedia,
                UploadedFile::fake()->createWithContent('replacement.txt', 'replacement-content'),
            );
            $replacementPath = $replacement->buildPath();

            expect($replacement->disk)->toBe('s3')
                ->and($replacement->folder)->toBe('current-folder')
                ->and($replacement->revision)->toBe(10);

            Storage::disk('s3')->assertExists($currentPath);
            Storage::disk('s3')->assertExists($replacementPath);
            Storage::disk('public')->assertExists($stalePath);
        } finally {
            DB::rollBack();
        }

        expect($staleMedia->fresh()?->hash)->toBe('current-object.txt')
            ->and($staleMedia->fresh()?->revision)->toBe(9)
            ->and(Storage::disk('public')->allFiles())->toBe([$stalePath])
            ->and(Storage::disk('s3')->allFiles())->toBe([$currentPath]);
    });

    it('aborts if the authoritative source changes again while staging', function () {
        $staleContents = 'stale-source';
        $currentContents = 'current-source';
        $latestContents = 'latest-source';
        $staleMedia = createMediaRecord([
            'filename' => 'stale.txt',
            'hash' => 'stale-object.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => strlen($staleContents),
            'disk' => 'public',
            'folder' => 'stale-folder',
            'type' => MediaType::DOCUMENT,
            'digest' => hash('sha256', $staleContents),
            'revision' => 2,
        ]);
        $stalePath = $staleMedia->buildPath();
        Storage::disk('public')->put($stalePath, $staleContents);

        $currentMedia = Media::query()->findOrFail($staleMedia->id);
        $currentMedia->forceFill([
            'filename' => 'current.txt',
            'hash' => 'current-object.txt',
            'size' => strlen($currentContents),
            'disk' => 's3',
            'folder' => 'current-folder',
            'digest' => hash('sha256', $currentContents),
            'revision' => 6,
        ])->save();
        $currentPath = $currentMedia->buildPath();
        Storage::disk('s3')->put($currentPath, $currentContents);

        app()->instance(
            MediaContentScanner::class,
            new class($staleMedia->id, $latestContents) implements MediaContentScanner
            {
                public function __construct(
                    private readonly string $mediaId,
                    private readonly string $contents,
                ) {}

                public function scan(UploadedFile $file): void
                {
                    $media = Media::query()->findOrFail($this->mediaId);
                    $media->forceFill([
                        'filename' => 'latest.txt',
                        'hash' => 'latest-object.txt',
                        'size' => strlen($this->contents),
                        'disk' => 'public',
                        'folder' => 'latest-folder',
                        'digest' => hash('sha256', $this->contents),
                        'revision' => 11,
                    ])->save();
                    Storage::disk('public')->put($media->buildPath(), $this->contents);
                }
            },
        );

        expect(fn () => app(ReplaceMediaFileAction::class)->execute(
            $staleMedia,
            UploadedFile::fake()->createWithContent('replacement.txt', 'replacement-content'),
        ))->toThrow(
            RuntimeException::class,
            "Media [{$staleMedia->id}] changed while its replacement was being staged.",
        );

        $latestMedia = $staleMedia->fresh();
        expect($latestMedia?->disk)->toBe('public')
            ->and($latestMedia?->folder)->toBe('latest-folder')
            ->and($latestMedia?->hash)->toBe('latest-object.txt')
            ->and($latestMedia?->revision)->toBe(11)
            ->and(Storage::disk('s3')->allFiles())->toBe([$currentPath])
            ->and(Storage::disk('public')->allFiles())->toBe([
                'media/latest-folder/latest-object.txt',
                $stalePath,
            ]);
    });
});

/* =================================================================
 * DetachMediaAction
 * ================================================================= */

describe('DetachMediaAction', function () {

    it('removes association between media and model', function () {
        $media = createMediaRecord();
        $user = createTestUser();

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => User::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        $action = app(DetachMediaAction::class);
        $deleted = $action->execute(media: $media, model: $user);

        expect($deleted)->toBe(1);

        $this->assertDatabaseMissing(MediaTables::MEDIA_ASSOCIATIONS, [
            'media_id' => $media->id,
            'associable_id' => $user->id,
        ]);
    });

    it('scopes detach to specific collection', function () {
        $media = createMediaRecord();
        $user = createTestUser();

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => User::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => User::class,
            'associable_id' => $user->id,
            'collection' => 'gallery',
            'order' => 1,
        ]);

        $action = app(DetachMediaAction::class);
        $deleted = $action->execute(media: $media, model: $user, collection: 'avatar');

        expect($deleted)->toBe(1);
        expect(MediaAssociation::where('media_id', $media->id)->count())->toBe(1);

        $this->assertDatabaseHas(MediaTables::MEDIA_ASSOCIATIONS, [
            'media_id' => $media->id,
            'collection' => 'gallery',
        ]);
    });

    it('accepts Media instance or string UUID', function () {
        $media = createMediaRecord();
        $user = createTestUser();

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => User::class,
            'associable_id' => $user->id,
            'collection' => 'default',
            'order' => 0,
        ]);

        $action = app(DetachMediaAction::class);
        $deleted = $action->execute(media: $media->id, model: $user);

        expect($deleted)->toBe(1);
    });

    it('returns 0 when no matching association', function () {
        $media = createMediaRecord();
        $user = createTestUser();

        $action = app(DetachMediaAction::class);
        $deleted = $action->execute(media: $media, model: $user);

        expect($deleted)->toBe(0);
    });

    it('removes associations after the Media record is soft deleted', function () {
        $media = createMediaRecord();
        $user = createTestUser();
        $association = MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => User::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);
        $media->delete();

        $deleted = app(DetachMediaAction::class)->execute(
            media: $media->id,
            model: $user,
            collection: 'avatar',
        );

        expect($deleted)->toBe(1)
            ->and(MediaAssociation::query()->whereKey($association->id)->exists())
            ->toBeFalse()
            ->and(Media::query()->withTrashed()->findOrFail($media->id)->trashed())
            ->toBeTrue();
    });
});

/* =================================================================
 * DeleteMediaAction
 * ================================================================= */

describe('DeleteMediaAction', function () {

    it('protects a reused public asset unless global deletion is forced', function () {
        $media = createMediaRecord(['is_public' => true]);
        $firstOwner = createTestUser();
        $secondOwner = createTestUser();
        $attach = app(AttachMediaAction::class);

        $attach->execute($media, $firstOwner, dispatchVariations: false);
        $attach->execute($media, $secondOwner, dispatchVariations: false);

        $action = app(DeleteMediaAction::class);

        expect(fn () => $action->execute($media))
            ->toThrow(MediaInUseException::class);
        expect(Media::query()->find($media->id))->not->toBeNull();

        expect($action->execute($media, force: true))->toBeTrue()
            ->and(Media::query()->find($media->id))->toBeNull();
    });

    it('soft-deletes the media record and retains its diagnostic tombstone', function () {
        $media = createMediaRecord();
        $media_id = $media->id;

        $action = app(DeleteMediaAction::class);
        $result = $action->execute($media);

        expect($result)->toBeTrue();
        expect(Media::query()->find($media_id))->toBeNull()
            ->and(Media::withTrashed()->find($media_id)?->trashed())->toBeTrue();
    });

    it('deletes file from disk', function () {
        Storage::fake('public');

        $media = createMediaRecord(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'abc123.jpg']);

        // Put a file on the fake disk
        Storage::disk('public')->put($media->buildPath(), 'file contents');
        Storage::disk('public')->assertExists($media->buildPath());

        config(['media.delete_files_on_media_delete' => true]);

        $action = app(DeleteMediaAction::class);
        $action->execute($media);

        Storage::disk('public')->assertMissing($media->buildPath());
    });

    it('deletes variation files from disk', function () {
        Storage::fake('public');

        $media = createMediaRecord(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'orig123.jpg']);

        Storage::disk('public')->put($media->buildPath(), 'original content');
        Storage::disk('public')->put(Media::storagePath('uploads').'/conversions/orig123-thumb.webp', 'thumb content');

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 256,
            'format' => 'webp',
            'quality' => 80,
        ]);

        config(['media.delete_files_on_media_delete' => true]);

        $action = app(DeleteMediaAction::class);
        $action->execute($media);

        Storage::disk('public')->assertMissing($media->buildPath());
        Storage::disk('public')->assertMissing(Media::storagePath('uploads').'/conversions/orig123-thumb.webp');
    });

    it('respects delete_files_on_media_delete config', function () {
        Storage::fake('public');

        $media = createMediaRecord(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'keep123.jpg']);

        Storage::disk('public')->put($media->buildPath(), 'keep this file');

        config(['media.delete_files_on_media_delete' => false]);

        $action = app(DeleteMediaAction::class);
        $action->execute($media);

        // File should still exist
        Storage::disk('public')->assertExists($media->buildPath());

        expect(Media::query()->find($media->id))->toBeNull()
            ->and(Media::withTrashed()->find($media->id)?->trashed())->toBeTrue();
    });

    it('accepts string UUID', function () {
        $media = createMediaRecord();
        $media_id = $media->id;

        $action = app(DeleteMediaAction::class);
        $result = $action->execute($media_id);

        expect($result)->toBeTrue();
        expect(Media::query()->find($media_id))->toBeNull()
            ->and(Media::withTrashed()->find($media_id)?->trashed())->toBeTrue();
    });

    it('does not delete files when the database transaction fails', function () {
        Storage::fake('public');

        $media = createMediaRecord(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'rollback.jpg']);

        Storage::disk('public')->put($media->buildPath(), 'file contents');
        Storage::disk('public')->assertExists($media->buildPath());

        $connection = DB::connection();
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('delete transaction failed'));

        $action = app(DeleteMediaAction::class);

        expect(fn () => $action->execute($media))
            ->toThrow(RuntimeException::class, 'delete transaction failed');

        Storage::disk('public')->assertExists($media->buildPath());
        expect(Media::find($media->id))->not->toBeNull();
    });
});
