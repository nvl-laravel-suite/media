<?php

declare(strict_types=1);

use Nvl\Media\Data\Display\MediaPayload;
use Nvl\Media\Data\Display\PublicMedia;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Tests\Stubs\TestMediaModel;

beforeEach(function () {
    config([
        'media.image_variation_presets' => [
            'thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true],
            'small' => ['width' => 320, 'height' => 320, 'quality' => 80, 'format' => 'webp', 'enabled' => true],
            'medium' => ['width' => 640, 'height' => 640, 'quality' => 85, 'format' => 'webp', 'enabled' => true],
            'large' => ['width' => 1280, 'height' => 1280, 'quality' => 85, 'format' => 'webp', 'enabled' => false],
        ],
    ]);

});

function createMediaDataTestModel(array $overrides = []): TestMediaModel
{
    return TestMediaModel::create(array_merge([
        'name' => 'Media DTO owner',
    ], $overrides));
}

function createMediaDataRecord(array $overrides = []): Media
{
    return Media::create(array_merge([
        'filename' => 'shared-image.jpg',
        'hash' => md5(uniqid('', true)).'.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 2048,
        'disk' => 'public',
        'folder' => 'products',
        'is_public' => true,
        'type' => MediaType::IMAGE,
        'digest' => md5('shared-image'),
        'metadata' => [
            'original_width' => '1200',
            'original_height' => '800',
        ],
    ], $overrides));
}

function attachMediaDataRecord(
    TestMediaModel $owner,
    Media $media,
    string $collection,
    array $metadata = [],
    int $order = 0,
): MediaAssociation {
    return MediaAssociation::create([
        'media_id' => $media->id,
        'associable_type' => $owner->getMorphClass(),
        'associable_id' => $owner->id,
        'collection' => $collection,
        'order' => $order,
        'metadata' => $metadata,
    ]);
}

it('serializes media using the association for the requested owner', function () {
    $productOwner = createMediaDataTestModel(['name' => 'Product owner']);
    $variantOwner = createMediaDataTestModel(['name' => 'Variant owner']);
    $media = createMediaDataRecord();

    attachMediaDataRecord($productOwner, $media, 'gallery', [
        'alt' => 'Product gallery alt',
    ]);

    attachMediaDataRecord($variantOwner, $media, 'featured', [
        'alt' => 'Variant featured alt',
    ], 3);

    $media->translations()->create([
        'locale' => 'bg',
        'title' => 'Преведено изображение',
        'alt' => 'Преведен alt',
        'caption' => 'Преведен надпис',
    ]);

    $media->imageVariations()->create([
        'label' => 'thumb',
        'width' => 320,
        'height' => 320,
        'size' => 512,
        'format' => 'webp',
        'quality' => 82,
    ]);

    $data = MediaPayload::fromMedia(
        $media->fresh(['associations', 'translations', 'imageVariations']),
        $variantOwner,
        'featured',
        'bg',
    );

    expect($data->modelType)->toBe($variantOwner->getMorphClass())
        ->and($data->modelId)->toBe($variantOwner->id)
        ->and($data->collectionName)->toBe('featured')
        ->and($data->order)->toBe(3)
        ->and($data->alt)->toBe('Variant featured alt')
        ->and($data->title)->toBe('Преведено изображение')
        ->and($data->caption)->toBe('Преведен надпис')
        ->and($data->image)->not->toBeNull()
        ->and($data->document)->toBeNull()
        ->and($data->file)->toBeNull();

    expect($data->image?->width)->toBe(1200)
        ->and($data->image?->height)->toBe(800)
        ->and($data->image?->aspectRatio)->toBe(1.5)
        ->and($data->image?->variations)->toHaveCount(1)
        ->and($data->previewUrl)->toContain('v=thumb');

    $breakpoints = collect($data->image?->breakpoints ?? []);
    $sizes = collect($data->image?->sizes ?? []);
    $generatedThumb = $sizes->first(
        fn ($size): bool => $size->label === 'thumb' && $size->source === 'variation'
    );
    $configuredThumb = $sizes->first(
        fn ($size): bool => $size->label === 'thumb' && $size->source === 'configured'
    );
    $medium = $sizes->firstWhere('label', 'medium');
    $largeBreakpoint = $breakpoints->firstWhere('label', 'large');

    expect($breakpoints->pluck('label')->all())->toBe(['thumb', 'small', 'medium', 'large'])
        ->and($largeBreakpoint?->enabled)->toBeFalse()
        ->and($sizes->pluck('label')->all())->toBe(['original', 'thumb', 'thumb', 'medium'])
        ->and($sizes->pluck('name')->all())->toBe(['original', 'small', 'thumbnail', 'medium'])
        ->and($sizes->pluck('source')->all())->toBe(['original', 'variation', 'configured', 'configured'])
        ->and($generatedThumb?->isGenerated)->toBeTrue()
        ->and($generatedThumb?->isAvailable)->toBeTrue()
        ->and($generatedThumb?->url)->toContain('v=thumb')
        ->and($configuredThumb?->isGenerated)->toBeFalse()
        ->and($configuredThumb?->isAvailable)->toBeFalse()
        ->and($configuredThumb?->url)->toBeNull()
        ->and($configuredThumb?->width)->toBe(150)
        ->and($configuredThumb?->height)->toBe(150)
        ->and($medium?->isAvailable)->toBeFalse()
        ->and($medium?->width)->toBe(640)
        ->and($medium?->height)->toBe(640);
});

it('uses document payloads only for document media', function () {
    $owner = createMediaDataTestModel();
    $media = createMediaDataRecord([
        'filename' => 'manual.pdf',
        'hash' => md5(uniqid('', true)).'.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
        'metadata' => null,
    ]);

    attachMediaDataRecord($owner, $media, 'manuals');

    $data = MediaPayload::fromMedia($media->fresh(['associations', 'translations', 'imageVariations']), $owner, 'manuals');

    expect($data->type)->toBe(MediaType::DOCUMENT)
        ->and($data->image)->toBeNull()
        ->and($data->document)->not->toBeNull()
        ->and($data->file)->toBeNull()
        ->and($data->document?->extension)->toBe('pdf')
        ->and($data->document?->mimeType)->toBe('application/pdf');
});

it('projects public-safe media data without internal attachment fields', function () {
    $owner = createMediaDataTestModel();
    $media = createMediaDataRecord();

    attachMediaDataRecord($owner, $media, 'featured', [
        'alt' => 'Public alt text',
    ]);

    $media->imageVariations()->create([
        'label' => 'thumb',
        'width' => 320,
        'height' => 320,
        'size' => 512,
        'format' => 'webp',
        'quality' => 82,
    ]);
    $media->imageVariations()->create([
        'label' => 'preview',
        'width' => 1200,
        'height' => 800,
        'size' => 1024,
        'format' => 'webp',
        'quality' => 90,
    ]);

    $data = PublicMedia::fromMedia(
        $media->fresh(['associations', 'translations', 'imageVariations']),
        $owner,
        'featured',
        'bg',
    );

    expect($data)->not->toBeNull()
        ->and($data?->collection)->toBe('featured')
        ->and($data?->alt)->toBe('Public alt text')
        ->and($data?->image)->not->toBeNull()
        ->and($data?->file)->toBeNull();

    $payload = $data?->toArray() ?? [];

    foreach (['modelType', 'modelId', 'disk', 'folder', 'isPublic', 'metadata', 'associationMetadata', 'fileName', 'name'] as $internalKey) {
        expect(array_key_exists($internalKey, $payload))->toBeFalse();
    }

    $sizes = collect($data?->image?->sizes ?? []);

    expect($sizes->pluck('label')->all())->toBe(['thumb', 'preview'])
        ->and($sizes->pluck('name')->all())->toBe(['small', 'preview'])
        ->and($sizes->pluck('source')->all())->toBe(['variation', 'variation'])
        ->and($data?->image?->srcSet)->toContain('320w')
        ->and($data?->image?->srcSet)->toContain('1200w')
        ->and($data?->image?->srcSet)->not->toContain('small')
        ->and($sizes->firstWhere('label', 'preview')?->size)->toBe(1024);

    expect($sizes->pluck('label')->all())->not->toContain('original');

    $privateMedia = createMediaDataRecord([
        'filename' => 'private-image.jpg',
        'is_public' => false,
    ]);
    attachMediaDataRecord($owner, $privateMedia, 'gallery');

    expect(PublicMedia::fromMedia($privateMedia->fresh(['associations', 'translations', 'imageVariations']), $owner, 'gallery'))->toBeNull();
});

it('projects original-only public images with an original responsive source', function () {
    $owner = createMediaDataTestModel();
    $media = createMediaDataRecord([
        'size' => 4096,
    ]);

    attachMediaDataRecord($owner, $media, 'featured', [
        'alt' => 'Original only alt',
    ]);

    $data = PublicMedia::fromMedia(
        $media->fresh(['associations', 'translations', 'imageVariations']),
        $owner,
        'featured',
        'en',
    );

    $sizes = collect($data?->image?->sizes ?? []);

    expect($data)->not->toBeNull()
        ->and($data?->image)->not->toBeNull()
        ->and($data?->image?->src)->toBe($data?->url)
        ->and($sizes->pluck('label')->all())->toBe(['original'])
        ->and($sizes->pluck('name')->all())->toBe(['original'])
        ->and($sizes->pluck('source')->all())->toBe(['original'])
        ->and($sizes->first()?->isGenerated)->toBeFalse()
        ->and($sizes->first()?->size)->toBe(4096)
        ->and($data?->image?->srcSet)->toContain('1200w');
});

it('keeps generated size mismatches and configured expectations separate', function () {
    $owner = createMediaDataTestModel();
    $media = createMediaDataRecord();

    attachMediaDataRecord($owner, $media, 'gallery');

    $media->imageVariations()->create([
        'label' => 'dyn-c6cc0a43e2923aca8a871840',
        'width' => 160,
        'height' => 160,
        'size' => 320,
        'format' => 'webp',
        'quality' => 85,
    ]);
    $media->imageVariations()->create([
        'label' => 'medium',
        'width' => 640,
        'height' => 429,
        'size' => 89954,
        'format' => 'webp',
        'quality' => 85,
    ]);

    $data = MediaPayload::fromMedia(
        $media->fresh(['associations', 'translations', 'imageVariations']),
        $owner,
        'gallery',
        'en',
    );

    $sizes = collect($data->image?->sizes ?? []);
    $dynamic = $sizes->firstWhere('label', 'dyn-c6cc0a43e2923aca8a871840');
    $mediums = $sizes
        ->filter(fn ($size): bool => $size->label === 'medium')
        ->values();

    expect($sizes->pluck('label')->all())->toBe([
        'original',
        'dyn-c6cc0a43e2923aca8a871840',
        'medium',
        'thumb',
        'small',
        'medium',
    ])
        ->and($dynamic?->name)->toBe('thumbnail')
        ->and($dynamic?->source)->toBe('variation')
        ->and($dynamic?->isAvailable)->toBeTrue()
        ->and($mediums)->toHaveCount(2)
        ->and($mediums->get(0)?->source)->toBe('variation')
        ->and($mediums->get(0)?->height)->toBe(429)
        ->and($mediums->get(0)?->isAvailable)->toBeTrue()
        ->and($mediums->get(1)?->source)->toBe('configured')
        ->and($mediums->get(1)?->height)->toBe(640)
        ->and($mediums->get(1)?->isAvailable)->toBeFalse();
});
