<?php

declare(strict_types=1);

use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaConfiguredVariationService;

function createConfiguredVariationMedia(array $overrides = []): Media
{
    $media = new Media;
    $media->id = $overrides['id'] ?? 'configured-variation-media';
    $media->filename = $overrides['filename'] ?? 'photo.jpg';
    $media->hash = $overrides['hash'] ?? 'configured-variation.jpg';
    $media->extension = $overrides['extension'] ?? 'jpg';
    $media->mime_type = $overrides['mime_type'] ?? 'image/jpeg';
    $media->size = $overrides['size'] ?? 1024;
    $media->disk = $overrides['disk'] ?? 'public';
    $media->folder = $overrides['folder'] ?? 'configured';
    $media->is_public = $overrides['is_public'] ?? true;
    $media->type = $overrides['type'] ?? MediaType::IMAGE;

    return $media;
}

beforeEach(function (): void {
    config([
        'media.image_variation_presets' => [
            'thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true],
            'small' => ['width' => 320, 'height' => 320, 'quality' => 80, 'format' => 'webp', 'enabled' => false],
        ],
        'media.output_conversion' => [
            'enabled' => true,
            'format' => 'webp',
            'quality' => 82,
            'skip_formats' => ['svg'],
        ],
    ]);
});

test('configured variation service resolves enabled preset definitions and output conversion', function (): void {
    $service = app(MediaConfiguredVariationService::class);
    $media = createConfiguredVariationMedia();

    $definitions = $service->configuredDefinitionsFor($media);

    expect($definitions)->toHaveKeys(['thumb', 'optimized'])
        ->and($definitions)->not->toHaveKey('small')
        ->and($definitions['thumb']->targetWidth)->toBe(150)
        ->and($definitions['optimized']->outputFormat)->toBe('webp')
        ->and($definitions['optimized']->targetQuality)->toBe(82);
});

test('configured variation service skips output conversion for excluded media formats', function (): void {
    $service = app(MediaConfiguredVariationService::class);
    $media = createConfiguredVariationMedia([
        'filename' => 'photo.svg',
        'hash' => 'configured-variation.svg',
        'extension' => 'svg',
        'mime_type' => 'image/svg+xml',
    ]);

    $definitions = $service->configuredDefinitionsFor($media);

    expect($definitions)->toHaveKey('thumb')
        ->and($definitions)->not->toHaveKey('optimized');
});

test('configured variation service prefers thumb for preview labels and falls back to first enabled preset', function (): void {
    $service = app(MediaConfiguredVariationService::class);

    expect($service->preferredPreviewVariationLabel())->toBe('thumb');

    config([
        'media.image_variation_presets' => [
            'thumb' => ['width' => 150, 'height' => 150, 'enabled' => false],
            'medium' => ['width' => 640, 'height' => 640, 'enabled' => true],
        ],
    ]);

    expect($service->preferredPreviewVariationLabel())->toBe('medium');
});
