<?php

declare(strict_types=1);

use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Tests\Stubs\TestMediaUser;

function createMedia(array $overrides = []): Media
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

function createUser(array $overrides = []): TestMediaUser
{
    return TestMediaUser::withoutEvents(
        static fn (): TestMediaUser => TestMediaUser::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'yIXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',

        ], $overrides)),
    );
}

/* =================================================================
 * Media Creation
 * ================================================================= */

describe('Media Creation', function () {

    it('creates a media record with all attributes', function () {
        $media = createMedia();

        expect($media)->toBeInstanceOf(Media::class)
            ->and($media->exists)->toBeTrue()
            ->and($media->filename)->toBe('test-image.jpg')
            ->and($media->extension)->toBe('jpg')
            ->and($media->mime_type)->toBe('image/jpeg')
            ->and($media->size)->toBe(1024)
            ->and($media->disk)->toBe('public')
            ->and($media->folder)->toBe('test')
            ->and($media->is_public)->toBeTrue()
            ->and($media->type)->toBe(MediaType::IMAGE)
            ->and($media->digest)->toBe(md5('test'))
            ->and($media->tags)->toBe(['hero', 'banner'])
            ->and($media->metadata)->toBe(['source' => 'upload']);
    });

    it('auto-generates UUID id', function () {
        $media = createMedia();

        expect($media->id)->not->toBeNull()
            ->and($media->id)->toBeString()
            ->and(strlen($media->id))->toBe(36);
    });

    it('casts type to MediaType enum', function () {
        $media = createMedia(['type' => MediaType::DOCUMENT]);

        expect($media->type)->toBeInstanceOf(MediaType::class)
            ->and($media->type)->toBe(MediaType::DOCUMENT);
    });

    it('casts tags and metadata as arrays', function () {
        $media = createMedia([
            'tags' => ['tag1', 'tag2'],
            'metadata' => ['key' => 'value'],
        ]);

        $fresh = $media->fresh();

        expect($fresh->tags)->toBeArray()->toBe(['tag1', 'tag2'])
            ->and($fresh->metadata)->toBeArray()->toBe(['key' => 'value']);
    });

    it('casts is_public as boolean', function () {
        $media = createMedia(['is_public' => true]);
        $fresh = $media->fresh();

        expect($fresh->is_public)->toBeBool()->toBeTrue();

        $media2 = createMedia(['is_public' => false]);
        $fresh2 = $media2->fresh();

        expect($fresh2->is_public)->toBeBool()->toBeFalse();
    });

    it('soft deletes', function () {
        $media = createMedia();
        $media_id = $media->id;

        $media->delete();

        expect(Media::find($media_id))->toBeNull()
            ->and(Media::withTrashed()->find($media_id))->not->toBeNull()
            ->and(Media::withTrashed()->find($media_id)->deleted_at)->not->toBeNull();
    });
});

/* =================================================================
 * Relationships
 * ================================================================= */

describe('Relationships', function () {

    it('has many associations', function () {
        $media = createMedia();
        $user = createUser();

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'gallery',
            'order' => 1,
        ]);

        $media->refresh();

        expect($media->associations)->toHaveCount(2)
            ->and($media->associations->first())->toBeInstanceOf(MediaAssociation::class);
    });

    it('has many image variations', function () {
        $media = createMedia();

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 512,
            'format' => 'webp',
            'quality' => 80,
        ]);

        $media->refresh();

        expect($media->imageVariations)->toHaveCount(1)
            ->and($media->imageVariations->first())->toBeInstanceOf(MediaImageVariation::class)
            ->and($media->imageVariations->first()->label)->toBe('thumb');
    });

    it('has many translations', function () {
        $media = createMedia();

        MediaTranslation::create([
            'media_id' => $media->id,
            'locale' => 'en',
            'title' => 'English Title',
            'alt' => 'English Alt',
            'caption' => 'English Caption',
        ]);

        MediaTranslation::create([
            'media_id' => $media->id,
            'locale' => 'el',
            'title' => 'Greek Title',
            'alt' => 'Greek Alt',
        ]);

        $media->refresh();

        expect($media->translations)->toHaveCount(2)
            ->and($media->translations->first())->toBeInstanceOf(MediaTranslation::class);
    });

    it('resolves a polymorphic uploader without assuming a consumer user class', function () {
        $user = createUser();
        $media = createMedia([
            'uploaded_by' => $user->id,
            'uploaded_by_type' => $user->getMorphClass(),
        ]);

        expect($media->uploader)->toBeInstanceOf(TestMediaUser::class)
            ->and($media->uploader->id)->toBe($user->id);
    });
});

/* =================================================================
 * Scopes
 * ================================================================= */

describe('Scopes', function () {

    it('filters public media', function () {
        createMedia(['is_public' => true]);
        createMedia(['is_public' => false]);
        createMedia(['is_public' => true]);

        $public = Media::public()->get();

        expect($public)->toHaveCount(2)
            ->and($public->every(fn (Media $m) => $m->is_public))->toBeTrue();
    });

    it('filters private media', function () {
        createMedia(['is_public' => true]);
        createMedia(['is_public' => false]);

        $private = Media::private()->get();

        expect($private)->toHaveCount(1)
            ->and($private->first()->is_public)->toBeFalse();
    });

    it('filters by type', function () {
        createMedia(['type' => MediaType::IMAGE]);
        createMedia(['type' => MediaType::DOCUMENT]);
        createMedia(['type' => MediaType::IMAGE]);

        $images = Media::ofType(MediaType::IMAGE)->get();

        expect($images)->toHaveCount(2)
            ->and($images->every(fn (Media $m) => $m->type === MediaType::IMAGE))->toBeTrue();
    });

    it('filters by disk', function () {
        createMedia(['disk' => 'public']);
        createMedia(['disk' => 's3']);
        createMedia(['disk' => 'public']);

        $public_disk = Media::onDisk('public')->get();

        expect($public_disk)->toHaveCount(2)
            ->and($public_disk->every(fn (Media $m) => $m->disk === 'public'))->toBeTrue();
    });

    it('filters by tag', function () {
        createMedia(['tags' => ['hero', 'banner']]);
        createMedia(['tags' => ['gallery']]);
        createMedia(['tags' => ['hero', 'featured']]);

        $hero_media = Media::withTag('hero')->get();

        expect($hero_media)->toHaveCount(2);
    });

    it('exposes chainable association and translation scopes', function () {
        $associationQuery = MediaAssociation::query();
        $translationQuery = MediaTranslation::query();

        expect($associationQuery->forCollection('gallery')->ordered())
            ->toBe($associationQuery)
            ->and($translationQuery->forLocale('en'))
            ->toBe($translationQuery);
    });
});

/* =================================================================
 * Tag Helpers
 * ================================================================= */

describe('Tag Helpers', function () {

    it('checks if media has a tag', function () {
        $media = createMedia(['tags' => ['hero', 'banner']]);

        expect($media->hasTag('hero'))->toBeTrue()
            ->and($media->hasTag('banner'))->toBeTrue()
            ->and($media->hasTag('missing'))->toBeFalse();
    });

    it('handles null tags gracefully', function () {
        $media = createMedia(['tags' => null]);

        expect($media->hasTag('anything'))->toBeFalse();
    });
});

/* =================================================================
 * Type Helpers
 * ================================================================= */

describe('Type Helpers', function () {

    it('correctly identifies image type', function () {
        $media = createMedia(['type' => MediaType::IMAGE]);

        expect($media->isImage())->toBeTrue()
            ->and($media->isVideo())->toBeFalse()
            ->and($media->isDocument())->toBeFalse()
            ->and($media->isAudio())->toBeFalse()
            ->and($media->isArchive())->toBeFalse();
    });

    it('correctly identifies video type', function () {
        $media = createMedia(['type' => MediaType::VIDEO]);

        expect($media->isVideo())->toBeTrue()
            ->and($media->isImage())->toBeFalse();
    });

    it('correctly identifies document type', function () {
        $media = createMedia(['type' => MediaType::DOCUMENT]);

        expect($media->isDocument())->toBeTrue()
            ->and($media->isImage())->toBeFalse();
    });

    it('correctly identifies audio type', function () {
        $media = createMedia(['type' => MediaType::AUDIO]);

        expect($media->isAudio())->toBeTrue()
            ->and($media->isImage())->toBeFalse();
    });

    it('correctly identifies archive type', function () {
        $media = createMedia(['type' => MediaType::ARCHIVE]);

        expect($media->isArchive())->toBeTrue()
            ->and($media->isImage())->toBeFalse();
    });
});

/* =================================================================
 * Human Readable Size
 * ================================================================= */

describe('Human Readable Size', function () {

    it('formats bytes correctly', function () {
        expect(createMedia(['size' => 500])->humanReadableSize())->toBe('500 B');
        expect(createMedia(['size' => 1024])->humanReadableSize())->toBe('1 KB');
        expect(createMedia(['size' => 1048576])->humanReadableSize())->toBe('1 MB');
        expect(createMedia(['size' => 1073741824])->humanReadableSize())->toBe('1 GB');
        expect(createMedia(['size' => 2560])->humanReadableSize())->toBe('2.5 KB');
    });
});

/* =================================================================
 * Variation Helpers
 * ================================================================= */

describe('Variation Helpers', function () {

    it('gets a variation by label', function () {
        $media = createMedia();

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

        $variation = $media->getVariation('thumb');

        expect($variation)->not->toBeNull()
            ->and($variation)->toBeInstanceOf(MediaImageVariation::class)
            ->and($variation->label)->toBe('thumb')
            ->and($variation->width)->toBe(150)
            ->and($variation->height)->toBe(150);
    });

    it('returns null for missing variation', function () {
        $media = createMedia();
        $media->load('imageVariations');

        expect($media->getVariation('nonexistent'))->toBeNull();
    });

    it('checks if variation exists with hasVariation', function () {
        $media = createMedia();

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'medium',
            'width' => 640,
            'height' => 640,
            'size' => 2048,
            'format' => 'webp',
            'quality' => 85,
        ]);

        $media->load('imageVariations');

        expect($media->hasVariation('medium'))->toBeTrue()
            ->and($media->hasVariation('nonexistent'))->toBeFalse();
    });
});

/* =================================================================
 * Usage Helpers
 * ================================================================= */

describe('Usage Helpers', function () {

    it('reports whether media is used', function () {
        $media = createMedia();
        $user = createUser();

        expect($media->isUsed())->toBeFalse();

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        expect($media->isUsed())->toBeTrue();
    });

    it('returns usages summary', function () {
        $media = createMedia();
        $user = createUser();

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => TestMediaUser::class,
            'associable_id' => $user->id,
            'collection' => 'avatar',
            'order' => 0,
        ]);

        $media->load('associations');
        $summary = $media->getUsagesSummary();

        expect($summary)->toHaveCount(1)
            ->and($summary->first()['type'])->toBe(TestMediaUser::class)
            ->and($summary->first()['id'])->toEqual($user->id)
            ->and($summary->first()['collection'])->toBe('avatar');
    });
});
