<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Config;
use League\Flysystem\DecoratedAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

function createMigratableMedia(array $overrides = []): Media
{
    return Media::create(array_merge([
        'filename' => 'migrate-test.jpg',
        'hash' => md5(uniqid('', true)).'.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'disk' => 'public',
        'folder' => 'vendors/test/docs',
        'is_public' => true,
        'type' => MediaType::IMAGE,
        'digest' => md5('migrate-test'),
    ], $overrides));
}

function associateMigratableMedia(Media $media, string $type, string $collection): void
{
    MediaAssociation::create([
        'media_id' => $media->id,
        'associable_type' => $type,
        'associable_id' => (string) Str::uuid(),
        'collection' => $collection,
        'locale' => null,
        'order' => 0,
        'metadata' => null,
    ]);
}

function installCorruptingMigrationDisk(string $disk): void
{
    config([
        "filesystems.disks.{$disk}" => [
            'driver' => 'local',
            'root' => storage_path("framework/testing/disks/{$disk}"),
        ],
    ]);

    $fake = Storage::fake($disk);
    $adapter = new class($fake->getAdapter()) extends DecoratedAdapter
    {
        /**
         * Write a same-size but content-corrupted stream for verification tests.
         *
         * @param  resource  $contents  Source stream
         */
        public function writeStream(string $path, $contents, Config $config): void
        {
            if (! is_resource($contents)) {
                throw new RuntimeException('Expected a readable stream.');
            }

            $payload = stream_get_contents($contents);

            if (! is_string($payload)) {
                throw new RuntimeException('Unable to read the source stream.');
            }

            $this->adapter->write($path, str_repeat('x', strlen($payload)), $config);
        }
    };

    Storage::set(
        $disk,
        new LaravelFilesystemAdapter(
            new Filesystem($adapter),
            $adapter,
            $fake->getConfig(),
        ),
    );
}

function failMigrationDiskDeletes(string $disk, bool $throwOnDelete): void
{
    $filesystem = Storage::disk($disk);
    $failingDisk = new class($filesystem->getDriver(), $filesystem->getAdapter(), $filesystem->getConfig(), $throwOnDelete) extends LaravelFilesystemAdapter
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
                throw new RuntimeException('Injected source deletion failure.');
            }

            return false;
        }
    };

    Storage::set($disk, $failingDisk);
}

it('updates disk records in records-only mode without requiring a configured source disk', function (): void {
    $media = createMigratableMedia(['disk' => 'legacy-media']);

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'legacy-media',
        '--to' => 'public',
        '--records-only' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($media->fresh()->disk)->toBe('public');
});

it('supports dry-run folder migrations without modifying database records', function (): void {
    $media = createMigratableMedia(['folder' => 'media/vendors/123/docs']);

    $this->artisan('nvl:media:migrate-disk', [
        '--column' => 'folder',
        '--from' => 'media/vendors',
        '--to' => 'vendors',
        '--dry-run' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($media->fresh()->folder)->toBe('media/vendors/123/docs');
});

it('migrates folder prefixes for matching database records', function (): void {
    $matching = createMigratableMedia([
        'disk' => 'public',
        'folder' => 'media/vendors/123/docs',
    ]);
    $otherDisk = createMigratableMedia([
        'disk' => 's3',
        'folder' => 'media/vendors/999/docs',
    ]);
    $alreadyClean = createMigratableMedia([
        'disk' => 'public',
        'folder' => 'vendors/123/docs',
    ]);

    $this->artisan('nvl:media:migrate-disk', [
        '--column' => 'folder',
        '--from' => 'media/vendors',
        '--to' => 'vendors',
        '--on-disk' => 'public',
    ])
        ->expectsConfirmation('Proceed?', 'yes')
        ->assertExitCode(0);

    expect($matching->fresh()->folder)->toBe('vendors/123/docs')
        ->and($otherDisk->fresh()->folder)->toBe('media/vendors/999/docs')
        ->and($alreadyClean->fresh()->folder)->toBe('vendors/123/docs');
});

it('moves objects to an R2-style destination disk without requiring a local destination path', function (): void {
    Storage::fake('public');
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    $media = createMigratableMedia([
        'disk' => 'public',
        'folder' => 'vendors/test/docs',
        'hash' => 'remote-migration.jpg',
    ]);

    Storage::disk('public')->put($media->buildPath(), 'media-content');

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'public',
        '--to' => 'cloudflare-r2',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($media->fresh()->disk)->toBe('cloudflare-r2');
    Storage::disk('public')->assertMissing($media->buildPath());
    Storage::disk('cloudflare-r2')->assertExists($media->buildPath());
});

it('verifies a matching pre-existing destination before removing the source', function (): void {
    Storage::fake('public');
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    $media = createMigratableMedia([
        'disk' => 'public',
        'hash' => 'pre-existing-match.jpg',
    ]);
    $contents = 'matching-media-content';

    Storage::disk('public')->put($media->buildPath(), $contents);
    Storage::disk('cloudflare-r2')->put($media->buildPath(), $contents);

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'public',
        '--to' => 'cloudflare-r2',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Verified existing destination and removed source')
        ->assertExitCode(0);

    expect($media->fresh()->disk)->toBe('cloudflare-r2')
        ->and(Storage::disk('cloudflare-r2')->get($media->buildPath()))->toBe($contents);

    Storage::disk('public')->assertMissing($media->buildPath());
});

it('rejects a same-size pre-existing destination with a different checksum', function (): void {
    Storage::fake('public');
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    $media = createMigratableMedia([
        'disk' => 'public',
        'hash' => 'pre-existing-mismatch.jpg',
    ]);
    $sourceContents = str_repeat('a', 32);
    $destinationContents = str_repeat('b', 32);

    Storage::disk('public')->put($media->buildPath(), $sourceContents);
    Storage::disk('cloudflare-r2')->put($media->buildPath(), $destinationContents);

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'public',
        '--to' => 'cloudflare-r2',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Existing destination failed size/checksum verification')
        ->assertExitCode(1);

    expect($media->fresh()->disk)->toBe('public')
        ->and(Storage::disk('public')->get($media->buildPath()))->toBe($sourceContents)
        ->and(Storage::disk('cloudflare-r2')->get($media->buildPath()))->toBe($destinationContents);
});

it('removes a newly copied destination when source deletion returns false', function (): void {
    Storage::fake('public');
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    $media = createMigratableMedia([
        'disk' => 'public',
        'hash' => 'source-delete-false.jpg',
    ]);
    $contents = 'source-delete-false-content';
    Storage::disk('public')->put($media->buildPath(), $contents);
    failMigrationDiskDeletes('public', throwOnDelete: false);

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'public',
        '--to' => 'cloudflare-r2',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Unable to complete a verified object move')
        ->assertExitCode(1);

    expect($media->fresh()->disk)->toBe('public')
        ->and(Storage::disk('public')->get($media->buildPath()))->toBe($contents);
    Storage::disk('cloudflare-r2')->assertMissing($media->buildPath());
});

it('preserves a pre-existing destination when source deletion throws', function (): void {
    Storage::fake('public');
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    $media = createMigratableMedia([
        'disk' => 'public',
        'hash' => 'source-delete-throws.jpg',
    ]);
    $contents = 'source-delete-throws-content';
    Storage::disk('public')->put($media->buildPath(), $contents);
    Storage::disk('cloudflare-r2')->put($media->buildPath(), $contents);
    failMigrationDiskDeletes('public', throwOnDelete: true);

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'public',
        '--to' => 'cloudflare-r2',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Injected source deletion failure')
        ->assertExitCode(1);

    expect($media->fresh()->disk)->toBe('public')
        ->and(Storage::disk('public')->get($media->buildPath()))->toBe($contents)
        ->and(Storage::disk('cloudflare-r2')->get($media->buildPath()))->toBe($contents);
});

it('keeps the source and database unchanged when a copied destination fails checksum verification', function (): void {
    Storage::fake('public');
    installCorruptingMigrationDisk('corrupting-destination');

    config([
        'media.allowed_disks' => ['public', 'corrupting-destination'],
    ]);

    $media = createMigratableMedia([
        'disk' => 'public',
        'hash' => 'corrupted-copy.jpg',
    ]);
    $contents = 'source-must-survive';

    Storage::disk('public')->put($media->buildPath(), $contents);

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'public',
        '--to' => 'corrupting-destination',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Unable to complete a verified object move')
        ->assertExitCode(1);

    expect($media->fresh()->disk)->toBe('public')
        ->and(Storage::disk('public')->get($media->buildPath()))->toBe($contents);

    Storage::disk('corrupting-destination')->assertMissing($media->buildPath());
});

it('restores a removed source without deleting a pre-existing destination when a later object fails', function (): void {
    Storage::fake('public');
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    $media = createMigratableMedia([
        'disk' => 'public',
        'hash' => 'rollback-pre-existing.jpg',
    ]);
    $variation = $media->imageVariations()->create([
        'label' => 'missing-variation',
        'width' => 150,
        'height' => 150,
        'size' => 100,
        'format' => 'webp',
        'quality' => 80,
    ]);
    $contents = 'rollback-media-content';

    Storage::disk('public')->put($media->buildPath(), $contents);
    Storage::disk('cloudflare-r2')->put($media->buildPath(), $contents);

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'public',
        '--to' => 'cloudflare-r2',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Source object is missing')
        ->assertExitCode(1);

    expect($media->fresh()->disk)->toBe('public')
        ->and(Storage::disk('public')->get($media->buildPath()))->toBe($contents)
        ->and(Storage::disk('cloudflare-r2')->get($media->buildPath()))->toBe($contents);

    Storage::disk('public')->assertMissing($variation->getPath());
    Storage::disk('cloudflare-r2')->assertMissing($variation->getPath());
});

it('restores the source and removes a newly created destination when a later object fails', function (): void {
    Storage::fake('public');
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    $media = createMigratableMedia([
        'disk' => 'public',
        'hash' => 'rollback-new-destination.jpg',
    ]);
    $variation = $media->imageVariations()->create([
        'label' => 'missing-new-destination-variation',
        'width' => 150,
        'height' => 150,
        'size' => 100,
        'format' => 'webp',
        'quality' => 80,
    ]);
    $contents = 'rollback-new-destination-content';

    Storage::disk('public')->put($media->buildPath(), $contents);

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'public',
        '--to' => 'cloudflare-r2',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Source object is missing')
        ->assertExitCode(1);

    expect($media->fresh()->disk)->toBe('public')
        ->and(Storage::disk('public')->get($media->buildPath()))->toBe($contents);

    Storage::disk('cloudflare-r2')->assertMissing($media->buildPath());
    Storage::disk('public')->assertMissing($variation->getPath());
    Storage::disk('cloudflare-r2')->assertMissing($variation->getPath());
});

it('rolls back every filesystem move when the final database update fails', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This failure injection uses an SQLite trigger.');
    }

    Storage::fake('public');
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    $newDestination = createMigratableMedia([
        'disk' => 'public',
        'hash' => 'database-rollback-new.jpg',
    ]);
    $preExistingDestination = createMigratableMedia([
        'disk' => 'public',
        'hash' => 'database-rollback-existing.jpg',
    ]);
    $newContents = 'database-rollback-new-content';
    $existingContents = 'database-rollback-existing-content';
    Storage::disk('public')->put($newDestination->buildPath(), $newContents);
    Storage::disk('public')->put($preExistingDestination->buildPath(), $existingContents);
    Storage::disk('cloudflare-r2')->put(
        $preExistingDestination->buildPath(),
        $existingContents,
    );

    DB::unprepared(sprintf(
        'CREATE TRIGGER media_disk_update_failure
        BEFORE UPDATE OF disk ON %s
        BEGIN
            SELECT RAISE(ABORT, "injected disk update failure");
        END',
        Media::TABLE,
    ));

    try {
        $this->artisan('nvl:media:migrate-disk', [
            '--from' => 'public',
            '--to' => 'cloudflare-r2',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Database update failed')
            ->assertExitCode(1);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS media_disk_update_failure');
    }

    expect($newDestination->fresh()->disk)->toBe('public')
        ->and($preExistingDestination->fresh()->disk)->toBe('public')
        ->and(Storage::disk('public')->get($newDestination->buildPath()))->toBe($newContents)
        ->and(Storage::disk('public')->get($preExistingDestination->buildPath()))->toBe($existingContents)
        ->and(Storage::disk('cloudflare-r2')->get($preExistingDestination->buildPath()))->toBe($existingContents);
    Storage::disk('cloudflare-r2')->assertMissing($newDestination->buildPath());
});

it('scopes disk migration by associable type and collection and moves variations', function (): void {
    Storage::fake('public');
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    $productMedia = createMigratableMedia([
        'disk' => 'public',
        'folder' => 'products/feed',
        'hash' => 'product-media.jpg',
    ]);
    $variantMedia = createMigratableMedia([
        'disk' => 'public',
        'folder' => 'products/variants',
        'hash' => 'variant-media.jpg',
    ]);
    $vendorMedia = createMigratableMedia([
        'disk' => 'public',
        'folder' => 'vendors/feed',
        'hash' => 'vendor-media.jpg',
    ]);

    associateMigratableMedia($productMedia, 'Domain\\Content\\Article', 'featured');
    associateMigratableMedia($variantMedia, 'Domain\\Content\\Page', 'gallery');
    associateMigratableMedia($vendorMedia, 'Domain\\Accounts\\Organization', 'featured');

    $variation = $productMedia->imageVariations()->create([
        'label' => 'thumb',
        'width' => 150,
        'height' => 150,
        'size' => 100,
        'format' => 'webp',
        'quality' => 80,
    ]);

    Storage::disk('public')->put($productMedia->buildPath(), 'product-content');
    Storage::disk('public')->put($variation->getPath(), 'product-thumb');
    Storage::disk('public')->put($variantMedia->buildPath(), 'variant-content');
    Storage::disk('public')->put($vendorMedia->buildPath(), 'vendor-content');

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'public',
        '--to' => 'cloudflare-r2',
        '--associable-type' => ['Domain\\Content\\Article'],
        '--collection' => ['featured'],
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($productMedia->fresh()->disk)->toBe('cloudflare-r2')
        ->and($variantMedia->fresh()->disk)->toBe('public')
        ->and($vendorMedia->fresh()->disk)->toBe('public');

    Storage::disk('public')->assertMissing($productMedia->buildPath());
    Storage::disk('public')->assertMissing($variation->getPath());
    Storage::disk('cloudflare-r2')->assertExists($productMedia->buildPath());
    Storage::disk('cloudflare-r2')->assertExists($variation->getPath());
    Storage::disk('public')->assertExists($variantMedia->buildPath());
    Storage::disk('public')->assertExists($vendorMedia->buildPath());
});

it('scopes records-only disk updates by comma-separated associable types', function (): void {
    $productMedia = createMigratableMedia(['disk' => 'legacy-media']);
    $variantMedia = createMigratableMedia(['disk' => 'legacy-media']);
    $vendorMedia = createMigratableMedia(['disk' => 'legacy-media']);

    associateMigratableMedia($productMedia, 'Domain\\Content\\Article', 'featured');
    associateMigratableMedia($variantMedia, 'Domain\\Content\\Page', 'gallery');
    associateMigratableMedia($vendorMedia, 'Domain\\Accounts\\Organization', 'featured');

    $this->artisan('nvl:media:migrate-disk', [
        '--from' => 'legacy-media',
        '--to' => 'public',
        '--records-only' => true,
        '--associable-type' => 'Domain\\Content\\Article,Domain\\Content\\Page',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($productMedia->fresh()->disk)->toBe('public')
        ->and($variantMedia->fresh()->disk)->toBe('public')
        ->and($vendorMedia->fresh()->disk)->toBe('legacy-media');
});
