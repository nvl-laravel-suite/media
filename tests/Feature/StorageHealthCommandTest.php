<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Console\Commands\StorageHealthCommand;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaPathResolver;

function configureStorageHealthR2Fake(): void
{
    Storage::fake('cloudflare-r2');

    config([
        'filesystems.disks.cloudflare-r2.driver' => 's3',
        'filesystems.disks.cloudflare-r2.url' => null,
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
        'media.disk' => 'cloudflare-r2',
        'media.assets.remote_public_delivery' => 'route',
    ]);
}

function createStorageHealthUser(): User
{
    return User::withoutEvents(
        static fn (): User => User::forceCreate([
            'name' => 'Storage Health User',
            'email' => fake()->unique()->safeEmail(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',

        ]),
    );
}

function createStorageHealthMedia(array $overrides = []): Media
{
    return Media::create(array_merge([
        'filename' => 'storage-health.jpg',
        'hash' => md5(uniqid('', true)).'.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'disk' => 'cloudflare-r2',
        'folder' => 'storage-health',
        'is_public' => true,
        'type' => MediaType::IMAGE,
        'digest' => md5('storage-health'),
    ], $overrides));
}

function createStorageHealthVariation(Media $media, array $overrides = []): MediaImageVariation
{
    return MediaImageVariation::create(array_merge([
        'media_id' => $media->id,
        'label' => 'thumb',
        'width' => 150,
        'height' => 150,
        'size' => 512,
        'format' => 'webp',
        'quality' => 80,
    ], $overrides));
}

function putStorageHealthOriginal(Media $media, string $contents = 'original'): void
{
    $path = app(MediaPathResolver::class)->mediaPath($media);

    Storage::disk($media->disk)->put($path, $contents);
}

function putStorageHealthVariation(Media $media, MediaImageVariation $variation, string $contents = 'variation'): void
{
    $path = app(MediaPathResolver::class)->variationPath($media, $variation->getFilename());

    Storage::disk($media->disk)->put($path, $contents);
}

it('passes read-only health checks for existing media originals and variations', function (): void {
    configureStorageHealthR2Fake();

    $media = createStorageHealthMedia();
    $variation = createStorageHealthVariation($media);

    putStorageHealthOriginal($media);
    putStorageHealthVariation($media, $variation);

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--sample' => 10,
        '--no-write' => true,
    ])
        ->expectsOutputToContain('Mode: read-only')
        ->expectsOutputToContain('Records checked: 1')
        ->expectsOutputToContain('Variations checked: 1')
        ->expectsOutputToContain('Media storage health check passed.')
        ->assertExitCode(0);

    expect(Storage::disk('cloudflare-r2')->allFiles('healthchecks'))->toBe([]);
});

it('fails when a sampled original object is missing', function (): void {
    configureStorageHealthR2Fake();

    $media = createStorageHealthMedia(['hash' => 'missing-original.jpg']);

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--sample' => 10,
        '--no-write' => true,
    ])
        ->expectsOutputToContain("Missing original for media [{$media->id}]")
        ->expectsOutputToContain('Media storage health check failed.')
        ->assertExitCode(1);
});

it('preserves storage exception details while checking objects', function (): void {
    configureStorageHealthR2Fake();

    $command = app(StorageHealthCommand::class);

    $checkObjectExists = new ReflectionMethod($command, 'checkObjectExists');
    /** @var array{exists: bool, error: string|null} $result */
    $result = $checkObjectExists->invoke($command, 'missing-disk', 'media/storage-exception.jpg');

    expect($result['exists'])->toBeFalse()
        ->and($result['error'])->toContain('InvalidArgumentException');

    $storageFailureMessage = new ReflectionMethod($command, 'storageFailureMessage');
    $message = $storageFailureMessage->invoke(
        $command,
        'Missing original for media [media-1]',
        'missing-disk',
        'media/storage-exception.jpg',
        $result['error'],
    );

    expect($message)->toContain('Storage error:')
        ->and($message)->toContain('InvalidArgumentException');
});

it('fails when an existing variation row points to a missing object', function (): void {
    configureStorageHealthR2Fake();

    $media = createStorageHealthMedia(['hash' => 'variation-source.jpg']);
    createStorageHealthVariation($media, ['label' => 'small']);

    putStorageHealthOriginal($media);

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--sample' => 10,
        '--no-write' => true,
    ])
        ->expectsOutputToContain("Missing variation [small] for media [{$media->id}]")
        ->assertExitCode(1);
});

it('verifies route-backed public and signed private media URL contracts', function (): void {
    configureStorageHealthR2Fake();

    $owner = createStorageHealthUser();
    $public = createStorageHealthMedia([
        'hash' => 'route-public.jpg',
        'is_public' => true,
    ]);
    $private = createStorageHealthMedia([
        'hash' => 'route-private.jpg',
        'is_public' => false,
        'uploaded_by' => $owner->id,
    ]);

    putStorageHealthOriginal($public);
    putStorageHealthOriginal($private);

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--sample' => 10,
        '--public-private-routes' => true,
        '--no-write' => true,
    ])
        ->expectsOutputToContain('Route contracts checked: 2')
        ->expectsOutputToContain('Media storage health check passed.')
        ->assertExitCode(0);
});

it('uses production mode as a read-only route and records verification preset', function (): void {
    configureStorageHealthR2Fake();

    $owner = createStorageHealthUser();
    $public = createStorageHealthMedia([
        'hash' => 'production-public.jpg',
        'is_public' => true,
    ]);
    $private = createStorageHealthMedia([
        'hash' => 'production-private.jpg',
        'is_public' => false,
        'uploaded_by' => $owner->id,
    ]);

    putStorageHealthOriginal($public);
    putStorageHealthOriginal($private);

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--sample' => 10,
        '--production' => true,
    ])
        ->expectsOutputToContain('Mode: production read-only')
        ->expectsOutputToContain('Records checked: 2')
        ->expectsOutputToContain('Route contracts checked: 2')
        ->expectsOutputToContain('Media storage health check passed.')
        ->assertExitCode(0);

    expect(Storage::disk('cloudflare-r2')->allFiles('healthchecks'))->toBe([]);
});

it('fails route verification when public remote media is configured for direct disk delivery', function (): void {
    configureStorageHealthR2Fake();

    config([
        'filesystems.disks.cloudflare-r2.url' => 'https://cdn.example.test',
        'media.assets.remote_public_delivery' => 'disk',
    ]);

    $media = createStorageHealthMedia(['hash' => 'direct-public.jpg']);
    putStorageHealthOriginal($media);

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--sample' => 10,
        '--public-private-routes' => true,
        '--no-write' => true,
    ])
        ->expectsOutputToContain("Public media [{$media->id}] generated non-route URL")
        ->assertExitCode(1);
});

it('runs the optional live write probe and cleans up the healthcheck object', function (): void {
    configureStorageHealthR2Fake();

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--sample' => 10,
        '--live-write' => true,
        '--cleanup' => true,
    ])
        ->expectsOutputToContain('Running live write probe')
        ->expectsOutputToContain('Media storage health check passed.')
        ->assertExitCode(0);

    expect(Storage::disk('cloudflare-r2')->allFiles('healthchecks'))->toBe([]);
});

it('can require at least one media record on the selected disk', function (): void {
    configureStorageHealthR2Fake();

    createStorageHealthMedia([
        'disk' => 'public',
        'folder' => 'other-disk',
        'hash' => 'other-disk.jpg',
    ]);

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--sample' => 10,
        '--require-records' => true,
        '--no-write' => true,
    ])
        ->expectsOutputToContain('No media records were found on required disk [cloudflare-r2].')
        ->assertExitCode(1);
});

it('production mode requires at least one media record on the selected disk', function (): void {
    configureStorageHealthR2Fake();

    createStorageHealthMedia([
        'disk' => 'public',
        'folder' => 'other-production-disk',
        'hash' => 'other-production-disk.jpg',
    ]);

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--sample' => 10,
        '--production' => true,
    ])
        ->expectsOutputToContain('Mode: production read-only')
        ->expectsOutputToContain('No media records were found on required disk [cloudflare-r2].')
        ->assertExitCode(1);
});

it('rejects combining live write mode with no-write mode', function (): void {
    configureStorageHealthR2Fake();

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--live-write' => true,
        '--no-write' => true,
    ])
        ->expectsOutputToContain('--live-write cannot be combined with --no-write.')
        ->assertExitCode(1);
});

it('rejects combining production mode with live write mode', function (): void {
    configureStorageHealthR2Fake();

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--production' => true,
        '--live-write' => true,
    ])
        ->expectsOutputToContain('--production cannot be combined with --live-write.')
        ->assertExitCode(1);
});

it('inventories orphan objects without deleting them by default', function (): void {
    configureStorageHealthR2Fake();
    $orphan = 'media/orphans/unreferenced.txt';
    Storage::disk('cloudflare-r2')->put($orphan, 'orphan');

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--orphans' => true,
        '--older-than' => 0,
    ])
        ->expectsOutputToContain($orphan)
        ->expectsOutputToContain('Orphan candidates: 1')
        ->expectsOutputToContain('Orphan objects deleted: 0')
        ->assertSuccessful();

    Storage::disk('cloudflare-r2')->assertExists($orphan);
});

it('deletes only explicitly requested age-eligible orphan objects', function (): void {
    configureStorageHealthR2Fake();
    $orphan = 'media/orphans/cleanup.txt';
    Storage::disk('cloudflare-r2')->put($orphan, 'orphan');

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--cleanup-orphans' => true,
        '--older-than' => 0,
    ])
        ->expectsOutputToContain('Orphan objects deleted: 1')
        ->assertSuccessful();

    Storage::disk('cloudflare-r2')->assertMissing($orphan);
});

it('reports objects referenced only by soft-deleted tombstones as candidates', function (): void {
    configureStorageHealthR2Fake();
    $media = createStorageHealthMedia(['hash' => 'tombstone.txt']);
    putStorageHealthOriginal($media, 'tombstone');
    $media->delete();

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--orphans' => true,
        '--older-than' => 0,
    ])
        ->expectsOutputToContain('soft-deleted media')
        ->assertSuccessful();

    expect(Media::withTrashed()->find($media->id)?->trashed())->toBeTrue();
    Storage::disk('cloudflare-r2')->assertExists($media->buildPath());
});

it('requires force before production orphan cleanup', function (): void {
    configureStorageHealthR2Fake();

    $this->artisan('nvl:media:reconcile', [
        '--disk' => 'cloudflare-r2',
        '--production' => true,
        '--cleanup-orphans' => true,
    ])
        ->expectsOutputToContain('--force is required for orphan cleanup in production.')
        ->assertFailed();
});
