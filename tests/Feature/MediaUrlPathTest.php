<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Support\MediaAssetUrl;

function createUrlMedia(array $overrides = []): Media
{
    return Media::create(array_merge([
        'filename' => 'photo.jpg',
        'hash' => 'abc123hash.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 2048,
        'disk' => 'public',
        'folder' => 'uploads/photos',
        'is_public' => true,
        'type' => MediaType::IMAGE,
        'digest' => md5('test'),
    ], $overrides));
}

/* =================================================================
 * getUrl()
 * ================================================================= */

describe('getUrl', function () {

    it('returns storage URL for public media', function () {
        Storage::fake('public');

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'test.jpg']);

        $url = $media->getUrl();

        expect($url)->toContain('uploads/test.jpg');
    });

    it('never returns an unsigned storage URL for private media', function () {
        Storage::fake('public');
        $media = createUrlMedia([
            'disk' => 'public',
            'is_public' => false,
            'uploaded_by' => 'owner-123',
        ]);

        expect($media->getUrl())
            ->toContain("/media/private/owner-123/{$media->id}")
            ->toContain('signature=')
            ->not->toContain('/storage/');
    });

    it('fails closed when private signed routes and temporary disk URLs are unavailable', function () {
        $media = createUrlMedia([
            'is_public' => false,
            'uploaded_by' => 'owner-123',
        ]);
        config(['media.assets.private_route_name' => 'media.private.missing']);

        MediaAssetUrl::privateUrl(
            media: $media,
            diskUrl: static fn (string $_disk, string $_path): string => 'https://public.example.test/unsafe.jpg',
            temporaryUrl: static function (string $_disk, string $_path, DateTimeInterface $_expiration): never {
                throw new RuntimeException('Temporary URLs are unsupported.');
            },
        );
    })->throws(RuntimeException::class, 'cannot be delivered securely');

    it('delegates to getVariationUrl when variation name given', function () {
        Storage::fake('public');

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'abc.jpg']);
        config(['media.conversions_folder' => 'conversions']);

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 512,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $media->load('imageVariations');

        // Put the variation file so it resolves
        /** @var MediaImageVariation $variation */
        $variation = $media->imageVariations->firstOrFail();
        Storage::disk('public')->put($variation->getPath(), 'variation data');

        $url = $media->getUrl('thumb');

        expect($url)->toContain('v=thumb')
            ->and($url)->not->toContain('conversions/abc-thumb.webp');
    });

    it('falls back to original when variation not found', function () {
        Storage::fake('public');

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'original.jpg']);
        $media->load('imageVariations');

        $url = $media->getUrl('nonexistent');

        expect($url)->toContain('uploads/original.jpg')
            ->and($url)->not->toContain('conversions');
    });
});

/* =================================================================
 * getPath()
 * ================================================================= */

describe('getPath', function () {

    it('returns absolute filesystem path for original', function () {
        Storage::fake('public');

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'test.jpg']);

        $path = $media->getPath();

        expect($path)->toContain('uploads/test.jpg');
    });

    it('returns variation path when variation exists', function () {
        Storage::fake('public');

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'abc.jpg']);
        config(['media.conversions_folder' => 'conversions']);

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 512,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $media->load('imageVariations');

        $path = $media->getPath('thumb');

        expect($path)->toContain('uploads/conversions/abc-thumb.webp');
    });

    it('falls back to original path when variation not found', function () {
        Storage::fake('public');

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'original.jpg']);
        $media->load('imageVariations');

        $path = $media->getPath('missing');

        expect($path)->toContain('uploads/original.jpg')
            ->and($path)->not->toContain('conversions');
    });
});

/* =================================================================
 * getVariationUrl()
 * ================================================================= */

describe('getVariationUrl', function () {

    it('returns variation URL when variation file exists on disk', function () {
        Storage::fake('public');
        config(['media.conversions_folder' => 'conversions']);

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'photos', 'hash' => 'img.jpg']);

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'small',
            'width' => 320,
            'height' => 320,
            'size' => 1024,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $media->load('imageVariations');

        // Put the variation file on disk
        /** @var MediaImageVariation $variation */
        $variation = $media->imageVariations->firstOrFail();
        Storage::disk('public')->put($variation->getPath(), 'content');

        $url = $media->getVariationUrl('small');

        expect($url)->toContain('v=small')
            ->and($url)->not->toContain('photos/conversions/img-small.webp');
    });

    it('falls back to original URL when variation file missing from disk', function () {
        Storage::fake('public');
        config(['media.conversions_folder' => 'conversions']);

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'photos', 'hash' => 'img.jpg']);

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'small',
            'width' => 320,
            'height' => 320,
            'size' => 1024,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $media->load('imageVariations');

        // Note: we do NOT put the variation file on disk

        $url = $media->getVariationUrl('small');

        // Should fall back to original since file doesn't exist
        expect($url)->toContain('photos/img.jpg');
    });

    it('falls back to original URL when variation label not found', function () {
        Storage::fake('public');

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'photos', 'hash' => 'img.jpg']);
        $media->load('imageVariations');

        $url = $media->getVariationUrl('nonexistent');

        expect($url)->toContain('photos/img.jpg');
    });
});

/* =================================================================
 * getVariationPath()
 * ================================================================= */

describe('getVariationPath', function () {

    it('returns variation absolute path when variation exists', function () {
        Storage::fake('public');
        config(['media.conversions_folder' => 'conversions']);

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'photos', 'hash' => 'img.jpg']);

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 512,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $media->load('imageVariations');

        $path = $media->getVariationPath('thumb');

        expect($path)->toContain('photos/conversions/img-thumb.webp');
    });

    it('falls back to original path when variation not found', function () {
        Storage::fake('public');

        $media = createUrlMedia(['disk' => 'public', 'folder' => 'photos', 'hash' => 'img.jpg']);
        $media->load('imageVariations');

        $path = $media->getVariationPath('missing');

        expect($path)->toContain('photos/img.jpg')
            ->and($path)->not->toContain('conversions');
    });
});

/* =================================================================
 * buildPath() (via getUrl/getPath)
 * ================================================================= */

describe('buildPath', function () {

    it('builds path from folder and hash', function () {
        Storage::fake('public');

        $media = createUrlMedia(['folder' => 'uploads/photos', 'hash' => 'abc123.jpg']);

        $url = $media->getUrl();

        expect($url)->toContain('uploads/photos/abc123.jpg');
    });

    it('handles null folder', function () {
        Storage::fake('public');

        $media = createUrlMedia(['folder' => null, 'hash' => 'abc123.jpg']);

        $url = $media->getUrl();

        expect($url)->toContain('abc123.jpg')
            ->and($url)->not->toContain('//abc123.jpg');
    });
});

/* =================================================================
 * buildUrl()/buildPublicUrl()/buildPrivateUrl()
 * ================================================================= */

describe('centralized URL builders', function () {
    beforeEach(function () {
        // Clear the disk URL so buildPublicUrl() falls through to the centralized route
        // instead of returning a direct storage URL.
        config(['filesystems.disks.public.url' => null]);
    });

    it('builds centralized public route URL', function () {
        $media = createUrlMedia(['is_public' => true]);

        $url = $media->buildPublicUrl();

        expect($url)->toContain("/media/assets/{$media->id}")
            ->toContain('version=');
    });

    it('builds centralized signed private route URL', function () {
        $owner = User::withoutEvents(
            static fn (): User => User::forceCreate([
                'name' => 'Owner',
                'email' => fake()->unique()->safeEmail(),
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',

            ]),
        );
        $media = createUrlMedia(['is_public' => false, 'uploaded_by' => $owner->id]);

        $url = $media->buildPrivateUrl();

        expect($url)->toContain("/media/private/{$owner->id}/{$media->id}")
            ->and($url)->toContain('signature=');
    });

    it('buildUrl chooses public or private route automatically', function () {
        $owner = User::withoutEvents(
            static fn (): User => User::forceCreate([
                'name' => 'Owner2',
                'email' => fake()->unique()->safeEmail(),
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',

            ]),
        );
        $public = createUrlMedia(['is_public' => true, 'hash' => 'public-url-hash.jpg']);
        $private = createUrlMedia([
            'is_public' => false,
            'uploaded_by' => $owner->id,
            'hash' => 'private-url-hash.jpg',
        ]);

        expect($public->buildUrl())->toContain("/media/assets/{$public->id}")
            ->and($private->buildUrl())->toContain("/media/private/{$owner->id}/{$private->id}");
    });

    it('falls back to legacy storage URL when configured public route name is invalid', function () {
        Storage::fake('public');
        config([
            'filesystems.disks.public.url' => config('app.url').'/storage',
            'media.assets.public_route_name' => 'media.assets.missing',
        ]);

        $media = createUrlMedia([
            'is_public' => true,
            'folder' => 'uploads',
            'hash' => 'public-route-fallback.jpg',
        ]);

        $url = $media->buildPublicUrl();

        expect($url)->toContain('uploads/public-route-fallback.jpg')
            ->and($url)->not->toContain('/media/assets/');
    });

    it('uses configurable private owner fallback and signed URL lifetime', function () {
        config([
            'media.assets.private_owner_fallback' => 'pool-owner',
            'media.assets.signed_url_lifetime' => 2,
        ]);

        $media = createUrlMedia([
            'is_public' => false,
            'uploaded_by' => null,
            'hash' => 'private-config-hash.jpg',
        ]);

        $url = $media->buildPrivateUrl();
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires = isset($query['expires']) ? (int) $query['expires'] : 0;

        expect($url)->toContain("/media/private/pool-owner/{$media->id}")
            ->and($expires)->toBeGreaterThan(0)
            ->and(abs($expires - now()->addMinutes(2)->timestamp))->toBeLessThanOrEqual(5);
    });
});

/* =================================================================
 * hasVariation() / getVariation()
 * ================================================================= */

describe('hasVariation and getVariation', function () {

    it('hasVariation returns true when label exists', function () {
        $media = createUrlMedia();

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 512,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $media->load('imageVariations');

        expect($media->hasVariation('thumb'))->toBeTrue()
            ->and($media->hasVariation('nonexistent'))->toBeFalse();
    });

    it('getVariation returns model for existing label', function () {
        $media = createUrlMedia();

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'medium',
            'width' => 640,
            'height' => 480,
            'size' => 2048,
            'format' => 'jpg',
            'quality' => 85,
        ]);

        $media->load('imageVariations');

        $variation = $media->getVariation('medium');

        expect($variation)->toBeInstanceOf(MediaImageVariation::class)
            ->and($variation->label)->toBe('medium')
            ->and($variation->width)->toBe(640);
    });

    it('getVariation returns null for missing label', function () {
        $media = createUrlMedia();
        $media->load('imageVariations');

        expect($media->getVariation('missing'))->toBeNull();
    });
});

/* =================================================================
 * isUsed()
 * ================================================================= */

describe('isUsed', function () {

    it('returns false when no associations exist', function () {
        $media = createUrlMedia();

        expect($media->isUsed())->toBeFalse();
    });

    it('returns true when associations exist', function () {
        $media = createUrlMedia();
        $user = User::withoutEvents(
            static fn (): User => User::forceCreate([
                'name' => 'Test',
                'email' => fake()->unique()->safeEmail(),
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',

            ]),
        );

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => User::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        expect($media->isUsed())->toBeTrue();
    });
});
