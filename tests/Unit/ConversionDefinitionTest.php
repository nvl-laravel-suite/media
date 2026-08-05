<?php

declare(strict_types=1);

use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\ImageCompression;
use Nvl\Media\Enums\ImageFit;
use Nvl\Media\Enums\ImageFormat;

beforeEach(function () {
    config([
        'media.queue.enabled' => true,
        'media.queue.connection' => 'database',
    ]);
});

/* =================================================================
 * Constructor & Defaults
 * ================================================================= */

describe('constructor', function () {

    it('sets the name from the constructor argument', function () {
        $def = new ConversionDefinition('thumb');

        expect($def->name)->toBe('thumb');
    });

    it('reads shouldBeQueued from config', function () {
        $def = new ConversionDefinition('thumb');

        expect($def->shouldBeQueued)->toBeTrue();
    });

    it('defaults shouldBeQueued to false when config is false', function () {
        config(['media.queue.enabled' => false]);

        $def = new ConversionDefinition('sync-conversion');

        expect($def->shouldBeQueued)->toBeFalse();
    });

    it('initialises all image manipulation properties to null or defaults', function () {
        $def = new ConversionDefinition('test');

        expect($def->targetWidth)->toBeNull();
        expect($def->targetHeight)->toBeNull();
        expect($def->fitMethod)->toBeNull();
        expect($def->fitWidth)->toBeNull();
        expect($def->fitHeight)->toBeNull();
        expect($def->cropWidth)->toBeNull();
        expect($def->cropHeight)->toBeNull();
        expect($def->cropPosition)->toBe('center');
        expect($def->targetQuality)->toBeNull();
        expect($def->outputFormat)->toBeNull();
        expect($def->sharpenAmount)->toBeNull();
        expect($def->blurAmount)->toBeNull();
        expect($def->applyGreyscale)->toBeFalse();
        expect($def->rotationDegrees)->toBeNull();
        expect($def->flipDirection)->toBeNull();
        expect($def->watermarkPath)->toBeNull();
        expect($def->watermarkPosition)->toBe('bottom-right');
        expect($def->watermarkOpacity)->toBe(50);
        expect($def->backgroundColor)->toBeNull();
        expect($def->brightnessAmount)->toBeNull();
        expect($def->contrastAmount)->toBeNull();
        expect($def->preserveOriginalFormat)->toBeFalse();
    });

    it('initialises behavior properties to defaults', function () {
        $def = new ConversionDefinition('test');

        expect($def->performOnSlots)->toBe([]);
        expect($def->queueName)->toBeNull();
        expect($def->enabled)->toBeTrue();
    });
});

/* =================================================================
 * Image Manipulation Methods
 * ================================================================= */

describe('width', function () {

    it('sets targetWidth', function () {
        $def = (new ConversionDefinition('thumb'))->width(300);

        expect($def->targetWidth)->toBe(300);
    });

    it('accepts null to clear width', function () {
        $def = (new ConversionDefinition('thumb'))->width(300)->width(null);

        expect($def->targetWidth)->toBeNull();
    });

    it('returns the definition for chaining', function () {
        $def = new ConversionDefinition('thumb');
        $result = $def->width(300);

        expect($result)->toBe($def);
    });
});

describe('height', function () {

    it('sets targetHeight', function () {
        $def = (new ConversionDefinition('thumb'))->height(200);

        expect($def->targetHeight)->toBe(200);
    });
});

describe('crop', function () {

    it('sets crop dimensions with default center position', function () {
        $def = (new ConversionDefinition('thumb'))->crop(150, 150);

        expect($def->cropWidth)->toBe(150);
        expect($def->cropHeight)->toBe(150);
        expect($def->cropPosition)->toBe('center');
    });

    it('sets custom crop position', function () {
        $def = (new ConversionDefinition('thumb'))->crop(200, 200, 'top-left');

        expect($def->cropPosition)->toBe('top-left');
    });
});

describe('fit', function () {

    it('sets fit method and dimensions', function () {
        $def = (new ConversionDefinition('cover'))->fit('crop', 600, 400);

        expect($def->fitMethod)->toBe('crop');
        expect($def->fitWidth)->toBe(600);
        expect($def->fitHeight)->toBe(400);
    });

    it('supports different fit methods', function () {
        $def = (new ConversionDefinition('contained'))->fit('contain', 800, 600);

        expect($def->fitMethod)->toBe('contain');
    });
});

describe('quality', function () {

    it('sets quality within valid range', function () {
        $def = (new ConversionDefinition('thumb'))->quality(85);

        expect($def->targetQuality)->toBe(85);
    });

    it('clamps quality to maximum of 100', function () {
        $def = (new ConversionDefinition('thumb'))->quality(150);

        expect($def->targetQuality)->toBe(100);
    });

    it('clamps quality to minimum of 0', function () {
        $def = (new ConversionDefinition('thumb'))->quality(-10);

        expect($def->targetQuality)->toBe(0);
    });

    it('accepts boundary values', function () {
        expect((new ConversionDefinition('a'))->quality(0)->targetQuality)->toBe(0);
        expect((new ConversionDefinition('b'))->quality(100)->targetQuality)->toBe(100);
    });
});

describe('format', function () {

    it('sets the output format', function () {
        $def = (new ConversionDefinition('thumb'))->format('webp');

        expect($def->outputFormat)->toBe('webp');
    });

    it('accepts typed format, fit, and compression configuration', function () {
        $definition = ConversionDefinition::fromPreset('card', [
            'max_size' => 1200,
            'fit' => ImageFit::Max,
            'format' => ImageFormat::Avif,
            'compression' => ImageCompression::Lossless,
            'quality' => 60,
        ]);

        expect($definition->fitMethod)->toBe('max')
            ->and($definition->fitWidth)->toBe(1200)
            ->and($definition->fitHeight)->toBe(1200)
            ->and($definition->outputFormat)->toBe('avif')
            ->and($definition->compression)->toBe(ImageCompression::Lossless)
            ->and($definition->targetQuality)->toBe(100);
    });

    it('requires positive max-size presets', function () {
        expect(fn () => ConversionDefinition::fromPreset('invalid', ['max_size' => 0]))
            ->toThrow(InvalidArgumentException::class, 'positive integer');
    });
});

describe('sharpen', function () {

    it('sets sharpen amount', function () {
        $def = (new ConversionDefinition('sharp'))->sharpen(15);

        expect($def->sharpenAmount)->toBe(15);
    });
});

describe('blur', function () {

    it('sets blur amount within valid range', function () {
        $def = (new ConversionDefinition('blurred'))->blur(25);

        expect($def->blurAmount)->toBe(25);
    });

    it('clamps blur to maximum of 100', function () {
        $def = (new ConversionDefinition('blurred'))->blur(200);

        expect($def->blurAmount)->toBe(100);
    });

    it('clamps blur to minimum of 0', function () {
        $def = (new ConversionDefinition('blurred'))->blur(-5);

        expect($def->blurAmount)->toBe(0);
    });
});

describe('greyscale', function () {

    it('sets applyGreyscale to true', function () {
        $def = (new ConversionDefinition('bw'))->greyscale();

        expect($def->applyGreyscale)->toBeTrue();
    });
});

describe('orientation', function () {

    it('sets rotation degrees', function () {
        $def = (new ConversionDefinition('rotated'))->orientation(90);

        expect($def->rotationDegrees)->toBe(90);
    });
});

describe('flip', function () {

    it('sets flip direction', function () {
        $def = (new ConversionDefinition('flipped'))->flip('h');

        expect($def->flipDirection)->toBe('h');
    });

    it('supports vertical direction', function () {
        $def = (new ConversionDefinition('flipped'))->flip('v');

        expect($def->flipDirection)->toBe('v');
    });
});

describe('watermark', function () {

    it('sets watermark with defaults', function () {
        $def = (new ConversionDefinition('marked'))->watermark('/img/logo.png');

        expect($def->watermarkPath)->toBe('/img/logo.png');
        expect($def->watermarkPosition)->toBe('bottom-right');
        expect($def->watermarkOpacity)->toBe(50);
    });

    it('accepts custom position and opacity', function () {
        $def = (new ConversionDefinition('marked'))->watermark('/img/logo.png', 'center', 80);

        expect($def->watermarkPath)->toBe('/img/logo.png');
        expect($def->watermarkPosition)->toBe('center');
        expect($def->watermarkOpacity)->toBe(80);
    });
});

describe('background', function () {

    it('sets background color', function () {
        $def = (new ConversionDefinition('padded'))->background('#ffffff');

        expect($def->backgroundColor)->toBe('#ffffff');
    });
});

describe('brightness', function () {

    it('sets brightness amount', function () {
        $def = (new ConversionDefinition('bright'))->brightness(20);

        expect($def->brightnessAmount)->toBe(20);
    });

    it('accepts negative values', function () {
        $def = (new ConversionDefinition('dark'))->brightness(-30);

        expect($def->brightnessAmount)->toBe(-30);
    });
});

describe('contrast', function () {

    it('sets contrast amount', function () {
        $def = (new ConversionDefinition('pop'))->contrast(15);

        expect($def->contrastAmount)->toBe(15);
    });
});

describe('keepOriginalImageFormat', function () {

    it('sets preserveOriginalFormat to true', function () {
        $def = (new ConversionDefinition('original'))->keepOriginalImageFormat();

        expect($def->preserveOriginalFormat)->toBeTrue();
    });
});

/* =================================================================
 * Behavior Methods
 * ================================================================= */

describe('performOnSlots', function () {

    it('restricts to specific slots', function () {
        $def = (new ConversionDefinition('thumb'))->performOnSlots('avatar', 'gallery');

        expect($def->performOnSlots)->toBe(['avatar', 'gallery']);
    });

    it('merges slots on multiple calls', function () {
        $def = (new ConversionDefinition('thumb'))
            ->performOnSlots('avatar')
            ->performOnSlots('gallery');

        expect($def->performOnSlots)->toBe(['avatar', 'gallery']);
    });

    it('deduplicates slot names', function () {
        $def = (new ConversionDefinition('thumb'))
            ->performOnSlots('avatar', 'gallery')
            ->performOnSlots('avatar');

        expect($def->performOnSlots)->toHaveCount(2);
    });
});

describe('queued', function () {

    it('sets shouldBeQueued to true', function () {
        config(['media.queue.enabled' => false]);
        $def = (new ConversionDefinition('thumb'))->queued();

        expect($def->shouldBeQueued)->toBeTrue();
    });
});

describe('nonQueued', function () {

    it('sets shouldBeQueued to false', function () {
        $def = (new ConversionDefinition('thumb'))->nonQueued();

        expect($def->shouldBeQueued)->toBeFalse();
    });
});

describe('onQueue', function () {

    it('sets shouldBeQueued to true and queue name', function () {
        $def = (new ConversionDefinition('thumb'))->nonQueued()->onQueue('media-processing');

        expect($def->shouldBeQueued)->toBeTrue();
        expect($def->queueName)->toBe('media-processing');
    });

    it('accepts null queue name', function () {
        $def = (new ConversionDefinition('thumb'))->onQueue(null);

        expect($def->shouldBeQueued)->toBeTrue();
        expect($def->queueName)->toBeNull();
    });

    it('accepts no argument (default null)', function () {
        $def = (new ConversionDefinition('thumb'))->onQueue();

        expect($def->shouldBeQueued)->toBeTrue();
        expect($def->queueName)->toBeNull();
    });
});

describe('enabled and disabled', function () {

    it('defaults to enabled', function () {
        $def = new ConversionDefinition('thumb');

        expect($def->enabled)->toBeTrue();
    });

    it('can be disabled via disabled()', function () {
        $def = (new ConversionDefinition('thumb'))->disabled();

        expect($def->enabled)->toBeFalse();
    });

    it('can be re-enabled via enabled()', function () {
        $def = (new ConversionDefinition('thumb'))->disabled()->enabled();

        expect($def->enabled)->toBeTrue();
    });

    it('accepts a boolean argument', function () {
        $def = (new ConversionDefinition('thumb'))->enabled(false);

        expect($def->enabled)->toBeFalse();
    });
});

/* =================================================================
 * Query Methods
 * ================================================================= */

describe('shouldBePerformedOn', function () {

    it('returns true when no slots are restricted', function () {
        $def = new ConversionDefinition('thumb');

        expect($def->shouldBePerformedOn('avatar'))->toBeTrue();
        expect($def->shouldBePerformedOn('gallery'))->toBeTrue();
        expect($def->shouldBePerformedOn('anything'))->toBeTrue();
    });

    it('returns true when slot is in the allowed list', function () {
        $def = (new ConversionDefinition('thumb'))->performOnSlots('avatar', 'gallery');

        expect($def->shouldBePerformedOn('avatar'))->toBeTrue();
        expect($def->shouldBePerformedOn('gallery'))->toBeTrue();
    });

    it('returns false when slot is not in the allowed list', function () {
        $def = (new ConversionDefinition('thumb'))->performOnSlots('avatar');

        expect($def->shouldBePerformedOn('gallery'))->toBeFalse();
        expect($def->shouldBePerformedOn('documents'))->toBeFalse();
    });
});

describe('getResultExtension', function () {

    it('returns the output format when set', function () {
        $def = (new ConversionDefinition('webp'))->format('webp');

        expect($def->getResultExtension('jpg'))->toBe('webp');
    });

    it('returns the original extension when no output format is set', function () {
        $def = new ConversionDefinition('thumb');

        expect($def->getResultExtension('png'))->toBe('png');
    });

    it('returns the original extension when preserveOriginalFormat is true', function () {
        $def = (new ConversionDefinition('thumb'))->format('webp')->keepOriginalImageFormat();

        expect($def->getResultExtension('png'))->toBe('png');
    });

    it('preserveOriginalFormat overrides outputFormat', function () {
        $def = (new ConversionDefinition('thumb'))
            ->format('avif')
            ->keepOriginalImageFormat();

        expect($def->getResultExtension('jpg'))->toBe('jpg');
    });
});

describe('getResultMimeType', function () {

    it('maps jpg format to image/jpeg', function () {
        $def = (new ConversionDefinition('thumb'))->format('jpg');

        expect($def->getResultMimeType('image/png'))->toBe('image/jpeg');
    });

    it('maps jpeg format to image/jpeg', function () {
        $def = (new ConversionDefinition('thumb'))->format('jpeg');

        expect($def->getResultMimeType('image/png'))->toBe('image/jpeg');
    });

    it('maps png format to image/png', function () {
        $def = (new ConversionDefinition('thumb'))->format('png');

        expect($def->getResultMimeType('image/jpeg'))->toBe('image/png');
    });

    it('maps webp format to image/webp', function () {
        $def = (new ConversionDefinition('thumb'))->format('webp');

        expect($def->getResultMimeType('image/jpeg'))->toBe('image/webp');
    });

    it('maps avif format to image/avif', function () {
        $def = (new ConversionDefinition('thumb'))->format('avif');

        expect($def->getResultMimeType('image/jpeg'))->toBe('image/avif');
    });

    it('maps gif format to image/gif', function () {
        $def = (new ConversionDefinition('thumb'))->format('gif');

        expect($def->getResultMimeType('image/jpeg'))->toBe('image/gif');
    });

    it('rejects unknown output formats', function () {
        expect(fn () => (new ConversionDefinition('thumb'))->format('tiff'))
            ->toThrow(InvalidArgumentException::class, 'Unsupported image output format');
    });

    it('returns original MIME type when no output format is set', function () {
        $def = new ConversionDefinition('thumb');

        expect($def->getResultMimeType('image/png'))->toBe('image/png');
    });

    it('returns original MIME type when preserveOriginalFormat is true', function () {
        $def = (new ConversionDefinition('thumb'))->format('webp')->keepOriginalImageFormat();

        expect($def->getResultMimeType('image/png'))->toBe('image/png');
    });
});

/* =================================================================
 * Fluent Chaining
 * ================================================================= */

describe('fluent chaining', function () {

    it('supports full method chain for image manipulation', function () {
        $def = (new ConversionDefinition('complex'))
            ->width(800)
            ->height(600)
            ->quality(90)
            ->format('webp')
            ->sharpen(10)
            ->greyscale()
            ->orientation(90)
            ->background('#000')
            ->brightness(5)
            ->contrast(10)
            ->performOnSlots('gallery', 'portfolio')
            ->nonQueued()
            ->disabled();

        expect($def->name)->toBe('complex');
        expect($def->targetWidth)->toBe(800);
        expect($def->targetHeight)->toBe(600);
        expect($def->targetQuality)->toBe(90);
        expect($def->outputFormat)->toBe('webp');
        expect($def->sharpenAmount)->toBe(10);
        expect($def->applyGreyscale)->toBeTrue();
        expect($def->rotationDegrees)->toBe(90);
        expect($def->backgroundColor)->toBe('#000');
        expect($def->brightnessAmount)->toBe(5);
        expect($def->contrastAmount)->toBe(10);
        expect($def->performOnSlots)->toBe(['gallery', 'portfolio']);
        expect($def->shouldBeQueued)->toBeFalse();
        expect($def->enabled)->toBeFalse();
    });
});
