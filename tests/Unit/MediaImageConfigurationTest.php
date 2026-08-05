<?php

declare(strict_types=1);

use Nvl\Media\Enums\ImageCompression;
use Nvl\Media\Enums\ImageFit;
use Nvl\Media\Enums\ImageFormat;
use Nvl\Media\Support\MediaImageConfiguration;
use Nvl\Media\Support\MediaVariationFileNamer;

it('normalizes enum-based presets and inherits format quality', function () {
    config([
        'media.image_formats.webp' => [
            'compression' => ImageCompression::Lossy,
            'quality' => 82,
        ],
        'media.image_variation_presets.card' => [
            'max_size' => 1200,
            'fit' => ImageFit::Max,
            'format' => ImageFormat::Webp,
            'enabled' => true,
        ],
    ]);

    $preset = MediaImageConfiguration::presets(['card'])['card'];

    expect($preset['max_size'])->toBe(1200)
        ->and($preset['fit'])->toBe('max')
        ->and($preset['format'])->toBe('webp')
        ->and($preset['compression'])->toBe('lossy')
        ->and($preset['quality'])->toBe(82);
});

it('forces quality one hundred for lossless output', function () {
    $preset = MediaImageConfiguration::normalizePreset([
        'format' => ImageFormat::Webp,
        'compression' => ImageCompression::Lossless,
        'quality' => 25,
    ]);

    expect($preset['compression'])->toBe('lossless')
        ->and($preset['quality'])->toBe(100);
});

it('skips output conversion for equivalent and unsafe source formats', function () {
    config([
        'media.output_conversion' => [
            'enabled' => true,
            'format' => ImageFormat::Webp,
            'max_size' => 1200,
            'fit' => ImageFit::Max,
            'skip_formats' => ['svg', 'gif'],
        ],
    ]);

    expect(MediaImageConfiguration::outputConversion('webp'))->toBeNull()
        ->and(MediaImageConfiguration::outputConversion('svg'))->toBeNull()
        ->and(MediaImageConfiguration::outputConversion('jpg'))->toMatchArray([
            'format' => 'webp',
            'max_size' => 1200,
            'fit' => 'max',
        ]);
});

it('builds compatible and dimension-bearing safe variation filenames', function () {
    config(['media.variation_naming.pattern' => '{basename}-{label}.{extension}']);

    expect(MediaVariationFileNamer::make('source.jpg', 'thumb', 160, 160, 'webp'))
        ->toBe('source-thumb.webp');

    config(['media.variation_naming.pattern' => '{basename}--{label}-{width}x{height}.{extension}']);

    expect(MediaVariationFileNamer::make('source.jpg', 'card', 960, 640, 'avif'))
        ->toBe('source--card-960x640.avif');
});

it('rejects unsafe variation labels and filename patterns', function () {
    expect(fn () => MediaVariationFileNamer::make('source.jpg', '../thumb', 160, 160, 'webp'))
        ->toThrow(InvalidArgumentException::class, 'not object-key safe');

    config(['media.variation_naming.pattern' => '../{basename}.{extension}']);

    expect(fn () => MediaVariationFileNamer::make('source.jpg', 'thumb', 160, 160, 'webp'))
        ->toThrow(InvalidArgumentException::class, 'unsafe object key');
});
