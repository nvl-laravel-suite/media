<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;

function createParentMedia(array $overrides = []): Media
{
    return Media::create(array_merge([
        'filename' => 'photo.jpg',
        'hash' => 'abc123def456.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 2048,
        'disk' => 'public',
        'folder' => 'uploads/users',
        'is_public' => true,
        'type' => MediaType::IMAGE,
        'digest' => md5('test'),
    ], $overrides));
}

function createVariation(Media $media, array $overrides = []): MediaImageVariation
{
    return MediaImageVariation::create(array_merge([
        'media_id' => $media->id,
        'label' => 'thumb',
        'width' => 150,
        'height' => 150,
        'size' => 512,
        'format' => 'webp',
        'quality' => 80,
        'status' => MediaLifecycleStatus::Available->value,
        'source_revision' => $media->revision,
    ], $overrides));
}

/* =================================================================
 * getFilename()
 * ================================================================= */

describe('getFilename', function () {

    it('builds filename from parent hash basename and label', function () {
        $media = createParentMedia(['hash' => 'abc123def456.jpg']);
        $variation = createVariation($media, ['label' => 'thumb', 'format' => 'webp']);

        expect($variation->getFilename())->toBe('abc123def456-thumb.webp');
    });

    it('uses the variation format as extension', function () {
        $media = createParentMedia(['hash' => 'myhash.jpg']);
        $variation = createVariation($media, ['label' => 'large', 'format' => 'png']);

        expect($variation->getFilename())->toBe('myhash-large.png');
    });

    it('handles different labels correctly', function () {
        $media = createParentMedia(['hash' => 'file123.jpg']);

        $thumb = createVariation($media, ['label' => 'thumb', 'format' => 'webp']);
        $medium = createVariation($media, ['label' => 'medium', 'format' => 'jpg']);

        expect($thumb->getFilename())->toBe('file123-thumb.webp')
            ->and($medium->getFilename())->toBe('file123-medium.jpg');
    });
});

/* =================================================================
 * getPath()
 * ================================================================= */

describe('getPath', function () {

    it('builds path from parent folder, conversions folder, and filename', function () {
        config(['media.conversions_folder' => 'conversions']);

        $media = createParentMedia(['folder' => 'uploads/users', 'hash' => 'abc123.jpg']);
        $variation = createVariation($media, ['label' => 'thumb', 'format' => 'webp']);

        expect($variation->getPath())->toBe(Media::storagePath('uploads/users').'/conversions/abc123-thumb.webp');
    });

    it('uses conversions folder directly when parent has no folder', function () {
        config(['media.conversions_folder' => 'conversions']);

        $media = createParentMedia(['folder' => null, 'hash' => 'abc123.jpg']);
        $variation = createVariation($media, ['label' => 'small', 'format' => 'webp']);

        expect($variation->getPath())->toBe(Media::storagePath('').'/conversions/abc123-small.webp');
    });

    it('respects custom conversions_folder config', function () {
        config(['media.conversions_folder' => 'variants']);

        $media = createParentMedia(['folder' => 'photos', 'hash' => 'img.jpg']);
        $variation = createVariation($media, ['label' => 'thumb', 'format' => 'webp']);

        expect($variation->getPath())->toBe(Media::storagePath('photos').'/variants/img-thumb.webp');
    });

    it('defaults to conversions folder from config', function () {
        config(['media.conversions_folder' => 'conversions']);

        $media = createParentMedia(['folder' => 'test', 'hash' => 'file.jpg']);
        $variation = createVariation($media, ['label' => 'thumb', 'format' => 'webp']);

        expect($variation->getPath())->toBe(Media::storagePath('test').'/conversions/file-thumb.webp');
    });
});

/* =================================================================
 * getUrl()
 * ================================================================= */

describe('getUrl', function () {

    it('returns storage URL when variation file exists on disk', function () {
        Storage::fake('public');

        $media = createParentMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'abc.jpg']);
        $variation = createVariation($media, ['label' => 'thumb', 'format' => 'webp']);

        // Put the variation file on disk
        $path = $variation->getPath();
        Storage::disk('public')->put($path, 'variation content');

        $url = $variation->getUrl();

        expect($url)->toContain('v=thumb')
            ->and($url)->not->toContain('uploads/conversions/abc-thumb.webp');
    });

    it('falls back to parent URL when variation file does not exist', function () {
        Storage::fake('public');

        $media = createParentMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'abc.jpg']);

        // Put parent file but NOT variation file
        Storage::disk('public')->put($media->buildPath(), 'original content');

        $variation = createVariation($media, ['label' => 'thumb', 'format' => 'webp']);

        $url = $variation->getUrl();

        // Should fall back to parent's URL
        expect($url)->toContain('uploads/abc.jpg')
            ->and($url)->not->toContain('conversions');
    });
});

/* =================================================================
 * getMimeType()
 * ================================================================= */

describe('getMimeType', function () {

    it('maps jpg to image/jpeg', function () {
        $media = createParentMedia();
        $variation = createVariation($media, ['format' => 'jpg']);

        expect($variation->getMimeType())->toBe('image/jpeg');
    });

    it('maps jpeg to image/jpeg', function () {
        $media = createParentMedia();
        $variation = createVariation($media, ['format' => 'jpeg']);

        expect($variation->getMimeType())->toBe('image/jpeg');
    });

    it('maps png to image/png', function () {
        $media = createParentMedia();
        $variation = createVariation($media, ['format' => 'png']);

        expect($variation->getMimeType())->toBe('image/png');
    });

    it('maps webp to image/webp', function () {
        $media = createParentMedia();
        $variation = createVariation($media, ['format' => 'webp']);

        expect($variation->getMimeType())->toBe('image/webp');
    });

    it('maps avif to image/avif', function () {
        $media = createParentMedia();
        $variation = createVariation($media, ['format' => 'avif']);

        expect($variation->getMimeType())->toBe('image/avif');
    });

    it('maps gif to image/gif', function () {
        $media = createParentMedia();
        $variation = createVariation($media, ['format' => 'gif']);

        expect($variation->getMimeType())->toBe('image/gif');
    });

    it('returns correct MIME for bmp format', function () {
        $media = createParentMedia();
        $variation = createVariation($media, ['format' => 'bmp']);

        expect($variation->getMimeType())->toBe('image/bmp');
    });

    it('returns application/octet-stream for unknown format', function () {
        $media = createParentMedia();
        $variation = createVariation($media, ['format' => 'xyz']);

        expect($variation->getMimeType())->toBe('application/octet-stream');
    });
});

/* =================================================================
 * Relationship
 * ================================================================= */

describe('media relationship', function () {

    it('belongs to parent media', function () {
        $media = createParentMedia();
        $variation = createVariation($media);

        $parent = $variation->media;

        expect($parent)->toBeInstanceOf(Media::class)
            ->and($parent->id)->toBe($media->id);
    });
});
