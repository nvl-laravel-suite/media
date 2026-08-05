<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaUrlResolver;
use Nvl\Media\Support\MediaAssetUrl;
use Nvl\Media\Support\MediaAssetVersion;

beforeEach(function () {
    config([
        'media.root_folder' => '',
        'media.conversions_folder' => 'conversions',
        'media.assets.public_route_name' => 'media.assets.show',
        'media.assets.private_route_name' => 'media.private.show',
        'media.assets.allowed_parameters' => ['v'],
        'media.assets.private_owner_fallback' => 'system',
        'media.assets.signed_url_lifetime' => 5,
        'media.assets.remote_public_delivery' => 'route',
    ]);

    if (! Route::has('media.assets.show')) {
        Route::get('/test-media-assets/{media}', static fn (): string => '')->name('media.assets.show');
    }
});

function createTestMedia(array $overrides = []): Media
{
    $media = new Media;
    $media->id = $overrides['id'] ?? '00000000-0000-4000-8000-000000000123';
    $media->filename = $overrides['filename'] ?? 'photo.jpg';
    $media->hash = $overrides['hash'] ?? 'abc123.jpg';
    $media->extension = $overrides['extension'] ?? 'jpg';
    $media->mime_type = $overrides['mime_type'] ?? 'image/jpeg';
    $media->size = $overrides['size'] ?? 1024;
    $media->disk = $overrides['disk'] ?? 'public';
    $media->folder = $overrides['folder'] ?? 'test';
    $media->is_public = $overrides['is_public'] ?? true;
    $media->type = $overrides['type'] ?? MediaType::IMAGE;
    $media->status = $overrides['status'] ?? MediaLifecycleStatus::Available;
    $media->revision = $overrides['revision'] ?? 1;

    return $media;
}

function createResolverVariation(Media $media, array $overrides = []): MediaImageVariation
{
    $variation = new MediaImageVariation;
    $variation->id = $overrides['id'] ?? 'resolver-variation-uuid';
    $variation->media_id = $media->id;
    $variation->label = $overrides['label'] ?? 'thumb';
    $variation->storage_path = $overrides['storage_path'] ?? null;
    $variation->status = $overrides['status'] ?? MediaLifecycleStatus::Available->value;
    $variation->width = $overrides['width'] ?? 150;
    $variation->height = $overrides['height'] ?? 150;
    $variation->size = $overrides['size'] ?? 512;
    $variation->format = $overrides['format'] ?? 'webp';
    $variation->quality = $overrides['quality'] ?? 80;
    $variation->source_revision = $overrides['source_revision'] ?? $media->revision;
    $variation->setRelation('media', $media);

    return $variation;
}

/* =================================================================
 * forMedia — disk-aware URL resolution
 * ================================================================= */

describe('forMedia', function () {

    it('returns direct URL for local disk with configured URL', function () {
        Storage::fake('public');
        $media = createTestMedia(['disk' => 'public']);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->forMedia($media);

        expect($url)->toBeString()->not->toBeEmpty();
        expect($url)->toContain('abc123.jpg');
    });

    it('returns route-based URL for local disk without URL', function () {
        Storage::fake('local');
        config(['filesystems.disks.local.url' => null]);

        $media = createTestMedia(['disk' => 'local']);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->forMedia($media);

        // May be empty string if route doesn't exist, but should not throw
        expect($url)->toBeString();
    });

    it('delegates to variation URL when variation label provided', function () {
        Storage::fake('public');
        $media = createTestMedia(['disk' => 'public']);

        // Load empty image variations
        $media->setRelation('imageVariations', collect());

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->forMedia($media, 'thumb');

        // Falls back to original since variation doesn't exist
        expect($url)->toBeString();
    });

    it('returns route-based URL for remote disks by default', function () {
        Storage::fake('cloudflare-r2');
        config([
            'filesystems.disks.cloudflare-r2.driver' => 's3',
            'media.assets.remote_public_delivery' => 'route',
        ]);

        $media = createTestMedia(['disk' => 'cloudflare-r2']);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->publicUrl($media);

        expect($url)->toContain('00000000-0000-4000-8000-000000000123')
            ->not->toContain('abc123.jpg');
    });

    it('keeps remote URLs route-based when route delivery has a configured disk URL', function () {
        Storage::fake('cloudflare-r2');
        config([
            'filesystems.disks.cloudflare-r2.driver' => 's3',
            'filesystems.disks.cloudflare-r2.url' => 'https://media.example.test',
            'media.assets.remote_public_delivery' => 'route',
        ]);

        $media = createTestMedia(['disk' => 'cloudflare-r2']);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->publicUrl($media);

        expect($url)->toContain('00000000-0000-4000-8000-000000000123')
            ->not->toContain('https://media.example.test')
            ->not->toContain('abc123.jpg');
    });

    it('can opt into direct disk URLs for remote public media', function () {
        Storage::fake('cloudflare-r2');
        config([
            'filesystems.disks.cloudflare-r2.driver' => 's3',
            'filesystems.disks.cloudflare-r2.url' => 'https://media.example.test',
            'media.assets.remote_public_delivery' => 'disk',
        ]);

        $media = createTestMedia(['disk' => 'cloudflare-r2']);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->publicUrl($media);

        expect($url)->toContain('abc123.jpg')
            ->not->toContain('/test-media-assets/');
    });
});

/* =================================================================
 * forVariation — variation URL with fallback
 * ================================================================= */

describe('forVariation', function () {

    it('falls back to parent media URL when variation file is missing', function () {
        Storage::fake('public');
        $media = createTestMedia(['disk' => 'public']);
        $media->setRelation('imageVariations', collect());

        $variation = createResolverVariation($media);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->forVariation($variation);

        // File doesn't exist on fake disk, so falls back to parent URL
        expect($url)->toBeString();
    });

    it('returns variation URL when file exists', function () {
        Storage::fake('public');
        $media = createTestMedia(['disk' => 'public']);

        $variation = createResolverVariation($media);
        $media->setRelation('imageVariations', collect([$variation]));

        // Put the variation file on disk
        $variationPath = $variation->getPath();
        Storage::disk('public')->put($variationPath, 'content');

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->forVariation($variation);

        expect($url)->toBeString()->not->toBeEmpty();
        expect($url)->toContain('v=thumb');
    });
});

/* =================================================================
 * buildUrl — public/private dispatch
 * ================================================================= */

describe('buildUrl', function () {

    it('dispatches to publicUrl for public media', function () {
        Storage::fake('public');
        $media = createTestMedia(['is_public' => true]);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->buildUrl($media);

        expect($url)->toBeString();
    });

    it('dispatches to privateUrl for private media', function () {
        Storage::fake('public');
        $media = createTestMedia(['is_public' => false, 'uploaded_by' => 'user-123']);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->buildUrl($media);

        expect($url)->toBeString();
    });
});

/* =================================================================
 * path — filesystem path resolution
 * ================================================================= */

describe('path', function () {

    it('returns absolute path for media', function () {
        Storage::fake('public');
        $media = createTestMedia(['disk' => 'public']);

        $resolver = app(MediaUrlResolver::class);
        $path = $resolver->path($media);

        expect($path)->toBeString()->not->toBeEmpty();
        expect($path)->toContain('abc123.jpg');
    });

    it('returns variation path when variation exists', function () {
        Storage::fake('public');
        $media = createTestMedia(['disk' => 'public']);

        $variation = createResolverVariation($media);

        $media->setRelation('imageVariations', collect([$variation]));

        $resolver = app(MediaUrlResolver::class);
        $path = $resolver->path($media, 'thumb');

        expect($path)->toBeString();
        expect($path)->toContain('conversions');
        expect($path)->toContain('webp');
    });

    it('returns original path when variation not found', function () {
        Storage::fake('public');
        $media = createTestMedia(['disk' => 'public']);
        $media->setRelation('imageVariations', collect());

        $resolver = app(MediaUrlResolver::class);
        $path = $resolver->path($media, 'nonexistent');

        expect($path)->toBeString();
        expect($path)->toContain('abc123.jpg');
    });

    it('rejects local path resolution for remote disks', function () {
        Storage::fake('cloudflare-r2');
        config(['filesystems.disks.cloudflare-r2.driver' => 's3']);

        $media = createTestMedia(['disk' => 'cloudflare-r2']);

        $resolver = app(MediaUrlResolver::class);

        expect(fn () => $resolver->path($media))
            ->toThrow(RuntimeException::class, 'does not support local path resolution');
    });
});

/* =================================================================
 * temporaryUrl
 * ================================================================= */

describe('temporaryUrl', function () {

    it('generates URL without variation', function () {
        Storage::fake('public');
        $media = createTestMedia(['disk' => 'public']);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->temporaryUrl($media, now()->addMinutes(5));

        // Local disk doesn't support temporaryUrl, falls back to url()
        expect($url)->toBeString();
    });
});

describe('delivery availability', function () {

    it('refuses every URL and path entry point for an unavailable parent', function () {
        Storage::fake('public');

        $media = createTestMedia([
            'disk' => 'public',
            'status' => MediaLifecycleStatus::PendingScan,
        ]);
        $variation = createResolverVariation($media);
        $media->setRelation('imageVariations', collect([$variation]));
        $resolver = app(MediaUrlResolver::class);
        $message = "Media [{$media->id}] is not available for delivery.";

        $publicResolvers = [
            static fn (): string => $resolver->forMedia($media),
            static fn (): string => $resolver->buildUrl($media),
            static fn (): string => $resolver->publicUrl($media),
            static fn (): string => $resolver->temporaryUrl($media, now()->addMinutes(5)),
            static fn (): string => $resolver->path($media),
            static fn (): string => $resolver->forVariation($variation),
        ];

        foreach ($publicResolvers as $resolve) {
            expect($resolve)->toThrow(RuntimeException::class, $message);
        }

        $media->is_public = false;
        $media->uploaded_by = 'user-123';

        expect(static fn (): string => $resolver->privateUrl($media))
            ->toThrow(RuntimeException::class, $message);
    });
});

describe('variation availability', function () {

    it('falls back from stale and failed variations without exposing their direct objects', function () {
        Storage::fake('cloudflare-r2');
        config([
            'filesystems.disks.cloudflare-r2.driver' => 's3',
            'filesystems.disks.cloudflare-r2.url' => 'https://media.example.test',
            'media.assets.remote_public_delivery' => 'disk',
        ]);

        $media = createTestMedia([
            'disk' => 'cloudflare-r2',
            'hash' => 'current-original.jpg',
            'revision' => 3,
        ]);
        $stale = createResolverVariation($media, [
            'id' => 'stale-variation',
            'label' => 'stale',
            'storage_path' => 'test/conversions/stale.webp',
            'source_revision' => 2,
        ]);
        $failed = createResolverVariation($media, [
            'id' => 'failed-variation',
            'label' => 'failed',
            'storage_path' => 'test/conversions/failed.webp',
            'status' => MediaLifecycleStatus::Failed->value,
        ]);
        $media->setRelation('imageVariations', collect([$stale, $failed]));
        Storage::disk('cloudflare-r2')->put($stale->getPath(), 'stale');
        Storage::disk('cloudflare-r2')->put($failed->getPath(), 'failed');

        $resolver = app(MediaUrlResolver::class);
        $urls = [
            $resolver->forMedia($media, 'stale'),
            $resolver->forVariation($stale),
            $resolver->publicUrl($media, ['v' => 'failed']),
            $resolver->temporaryUrl($media, now()->addMinutes(5), 'failed'),
        ];
        $media->is_public = false;
        $media->uploaded_by = 'user-123';
        config(['media.assets.private_route_name' => 'media.private.missing']);
        $urls[] = MediaAssetUrl::privateUrl(
            media: $media,
            parameters: ['v' => 'stale'],
            temporaryUrl: static fn (
                string $_disk,
                string $path,
                DateTimeInterface $_expiration,
            ): string => "https://temporary.example.test/{$path}",
        );

        foreach ($urls as $url) {
            expect($url)
                ->toContain('current-original.jpg')
                ->not->toContain('stale.webp')
                ->not->toContain('failed.webp')
                ->not->toContain('v=stale')
                ->not->toContain('v=failed');
        }
    });

    it('uses only a current available variation for path and cache version resolution', function () {
        Storage::fake('public');
        config(['filesystems.disks.public.url' => null]);

        $media = createTestMedia([
            'disk' => 'public',
            'hash' => 'current-original.jpg',
            'revision' => 4,
        ]);
        $current = createResolverVariation($media, [
            'id' => 'current-variation',
            'label' => 'current',
            'storage_path' => 'test/conversions/current.webp',
        ]);
        $stale = createResolverVariation($media, [
            'id' => 'stale-variation',
            'label' => 'stale',
            'storage_path' => 'test/conversions/stale.webp',
            'source_revision' => 3,
        ]);
        $failed = createResolverVariation($media, [
            'id' => 'failed-variation',
            'label' => 'failed',
            'storage_path' => 'test/conversions/failed.webp',
            'status' => MediaLifecycleStatus::Failed->value,
        ]);
        $media->setRelation('imageVariations', collect([$current, $stale, $failed]));

        $resolver = app(MediaUrlResolver::class);
        $currentUrl = $resolver->publicUrl($media, ['v' => 'current']);
        $query = [];
        parse_str((string) parse_url($currentUrl, PHP_URL_QUERY), $query);

        expect($resolver->path($media, 'current'))
            ->toContain('current.webp')
            ->and($resolver->path($media, 'stale'))
            ->toContain('current-original.jpg')
            ->not->toContain('stale.webp')
            ->and($resolver->path($media, 'failed'))
            ->toContain('current-original.jpg')
            ->not->toContain('failed.webp')
            ->and($query)
            ->toMatchArray([
                'v' => 'current',
                'version' => MediaAssetVersion::short($media, $current),
            ]);
    });
});

/* =================================================================
 * Parameter normalization (private but tested via publicUrl)
 * ================================================================= */

describe('parameter normalization', function () {

    it('does not let configuration broaden the supported parameter set', function () {
        Storage::fake('public');
        config(['media.assets.allowed_parameters' => ['v', 'w']]);

        $media = createTestMedia(['disk' => 'public']);
        $variation = createResolverVariation($media);
        $media->setRelation('imageVariations', collect([$variation]));

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->publicUrl($media, ['v' => 'thumb', 'w' => 300, 'evil' => 'hack']);

        expect($url)->toContain('v=thumb');
        expect($url)->not->toContain('w=300');
        expect($url)->not->toContain('evil');
        expect($url)->not->toContain('hack');
    });

    it('lets configuration disable named variation parameters', function () {
        Storage::fake('cloudflare-r2');
        config([
            'filesystems.disks.cloudflare-r2.driver' => 's3',
            'filesystems.disks.cloudflare-r2.url' => 'https://media.example.test',
            'media.assets.remote_public_delivery' => 'route',
            'media.assets.allowed_parameters' => [],
        ]);

        $media = createTestMedia(['disk' => 'cloudflare-r2']);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->publicUrl($media, ['v' => 'thumb']);

        expect($url)->toContain('00000000-0000-4000-8000-000000000123')
            ->not->toContain('v=thumb')
            ->not->toContain('https://media.example.test')
            ->not->toContain('abc123.jpg');
    });

    it('honors the narrowed allowlist for direct remote disk delivery', function () {
        Storage::fake('cloudflare-r2');
        config([
            'filesystems.disks.cloudflare-r2.driver' => 's3',
            'filesystems.disks.cloudflare-r2.url' => 'https://media.example.test',
            'media.assets.remote_public_delivery' => 'disk',
            'media.assets.allowed_parameters' => [],
        ]);

        $media = createTestMedia(['disk' => 'cloudflare-r2']);
        $variation = new MediaImageVariation;
        $variation->label = 'thumb';
        $variation->storage_path = 'test/conversions/thumb.webp';
        $media->setRelation('imageVariations', collect([$variation]));
        Storage::disk('cloudflare-r2')->put($variation->getPath(), 'variation');

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->publicUrl($media, ['v' => 'thumb']);

        expect($url)->toContain('abc123.jpg')
            ->not->toContain('thumb.webp');
    });

    it('omits disabled named variation parameters from private signed URLs', function () {
        Storage::fake('public');
        config(['media.assets.allowed_parameters' => []]);

        $media = createTestMedia([
            'disk' => 'public',
            'is_public' => false,
            'uploaded_by' => 'user-123',
        ]);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->privateUrl($media, ['v' => 'thumb']);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($query)
            ->not->toHaveKey('v')
            ->toHaveKeys(['expires', 'signature']);
    });

    it('uses normalized parameters when private signed routes fall back to temporary disk URLs', function () {
        config([
            'media.assets.allowed_parameters' => [],
            'media.assets.private_route_name' => 'media.private.missing',
        ]);

        $media = createTestMedia([
            'disk' => 'public',
            'is_public' => false,
            'uploaded_by' => 'user-123',
        ]);
        $variation = new MediaImageVariation;
        $variation->label = 'thumb';
        $variation->storage_path = 'test/conversions/thumb.webp';
        $media->setRelation('imageVariations', collect([$variation]));

        $url = MediaAssetUrl::privateUrl(
            media: $media,
            parameters: ['v' => 'thumb'],
            temporaryUrl: static fn (
                string $_disk,
                string $path,
                DateTimeInterface $_expiration,
            ): string => "https://temporary.example.test/{$path}",
        );

        expect($url)
            ->toContain('abc123.jpg')
            ->not->toContain('thumb.webp');
    });

    it('publicUrl strips null and empty parameters', function () {
        Storage::fake('public');
        $media = createTestMedia(['disk' => 'public']);

        $resolver = app(MediaUrlResolver::class);
        $url = $resolver->publicUrl($media, ['v' => null, 'w' => '', 'h' => 200]);

        expect($url)->not->toContain('v=');
        expect($url)->not->toContain('w=');
        expect($url)->not->toContain('h=');
    });
});
