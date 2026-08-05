<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Support\MediaAssetVersion;

function createHeaderTestMedia(array $overrides = []): Media
{
    return Media::create(array_merge([
        'filename' => 'header-test.jpg',
        'hash' => 'header-hash-'.uniqid().'.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'disk' => 'public',
        'folder' => 'headers',
        'is_public' => true,
        'type' => MediaType::IMAGE,
        'digest' => md5(uniqid()),
    ], $overrides));
}

function createTinyJpeg(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagejpeg($image, null, 90);
    $binary = (string) ob_get_clean();
    imagedestroy($image);

    return $binary;
}

describe('Content-Type and security headers', function () {
    beforeEach(fn () => Storage::fake('public'));

    it('serves correct Content-Type header', function () {
        $media = createHeaderTestMedia();
        Storage::disk('public')->put($media->buildPath(), createTinyJpeg());

        $response = $this->get("/media/assets/{$media->id}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    });
});

describe('ETag header', function () {
    beforeEach(fn () => Storage::fake('public'));

    it('includes ETag header based on media hash', function () {
        $media = createHeaderTestMedia(['hash' => 'etag-test-hash.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpeg());

        $response = $this->get("/media/assets/{$media->id}");

        $response->assertOk()
            ->assertHeader('ETag', '"etag-test-hash.jpg"');
    });

    it('returns 304 Not Modified when If-None-Match matches ETag', function () {
        $media = createHeaderTestMedia(['hash' => 'matching-etag.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpeg());

        $response = $this->withHeaders([
            'If-None-Match' => '"matching-etag.jpg"',
        ])->get("/media/assets/{$media->id}");

        $response->assertStatus(304);
    });

    it('returns 200 when If-None-Match does not match', function () {
        $media = createHeaderTestMedia(['hash' => 'current-hash.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpeg());

        $response = $this->withHeaders([
            'If-None-Match' => '"old-hash.jpg"',
        ])->get("/media/assets/{$media->id}");

        $response->assertOk();
    });

    it('uses a distinct ETag for a generated variation', function () {
        $media = createHeaderTestMedia(['hash' => 'variation-parent.jpg']);
        $variation = MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 100,
            'height' => 100,
            'size' => 17,
            'format' => 'webp',
            'quality' => 80,
        ]);
        Storage::disk('public')->put($variation->getPath(), 'variation-content');

        $etag = MediaAssetVersion::etag($media, $variation);

        $this->get("/media/assets/{$media->id}?v=thumb")
            ->assertOk()
            ->assertHeader('ETag', "\"{$etag}\"");

        $this->withHeaders([
            'If-None-Match' => "\"unrelated\", W/\"{$etag}\"",
        ])->get("/media/assets/{$media->id}?v=thumb")
            ->assertStatus(304);
    });
});

describe('Cache-Control headers', function () {
    beforeEach(fn () => Storage::fake('public'));

    it('uses public immutable cache for public assets', function () {
        config([
            'filesystems.disks.public.url' => null,
            'media.assets.public_cache_control' => 'public, max-age=31536000, immutable',
        ]);

        $media = createHeaderTestMedia();
        Storage::disk('public')->put($media->buildPath(), createTinyJpeg());

        $response = $this->get($media->buildPublicUrl());

        $cacheControl = (string) $response->headers->get('Cache-Control');

        expect($cacheControl)->toContain('public')
            ->toContain('immutable');
    });
});

describe('HTTP delivery semantics', function () {
    beforeEach(fn () => Storage::fake('public'));

    it('supports byte ranges for local assets', function () {
        $media = createHeaderTestMedia();
        Storage::disk('public')->put($media->buildPath(), '0123456789');

        $response = $this->withHeader('Range', 'bytes=2-5')
            ->get("/media/assets/{$media->id}");

        $response->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4');

        expect($response->streamedContent())->toBe('2345');
    });

    it('supports range delivery through remote-style disks', function () {
        Storage::fake('s3');
        config(['filesystems.disks.s3.driver' => 's3']);

        $media = createHeaderTestMedia([
            'disk' => 's3',
            'hash' => 'remote-range.jpg',
        ]);
        Storage::disk('s3')->put($media->buildPath(), 'abcdefghij');

        $response = $this->withHeader('Range', 'bytes=4-')
            ->get("/media/assets/{$media->id}");

        $response->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 4-9/10')
            ->assertHeader('Content-Length', '6');

        expect($response->streamedContent())->toBe('efghij');
    });

    it('returns asset metadata without a response body for HEAD', function () {
        $media = createHeaderTestMedia();
        Storage::disk('public')->put($media->buildPath(), '0123456789');

        $response = $this->call('HEAD', "/media/assets/{$media->id}");

        $response->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', '10')
            ->assertHeader('Content-Disposition');

        expect($response->getContent())->toBeFalse();
    });

    it('rejects unsatisfiable ranges', function () {
        $media = createHeaderTestMedia();
        Storage::disk('public')->put($media->buildPath(), '0123456789');

        $this->withHeader('Range', 'bytes=50-60')
            ->get("/media/assets/{$media->id}")
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
    });
});

describe('private asset serving', function () {
    beforeEach(fn () => Storage::fake('public'));

    it('returns 404 for private media on public route', function () {
        $media = createHeaderTestMedia(['is_public' => false]);
        Storage::disk('public')->put($media->buildPath(), createTinyJpeg());

        $response = $this->get("/media/assets/{$media->id}");

        $response->assertNotFound();
    });
});
