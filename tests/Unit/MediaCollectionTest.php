<?php

declare(strict_types=1);

use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Slots\MediaSlot;

beforeEach(function () {
    config([
        'filesystems.default' => 'public',
        'media.disk' => 'public',
        'media.default_path' => '{model_type}/{model_id}',
    ]);
});

/* =================================================================
 * Constructor & Config Defaults
 * ================================================================= */

describe('constructor', function () {

    it('sets the name from the constructor argument', function () {
        $collection = new MediaSlot('avatar');

        expect($collection->name)->toBe('avatar');
    });

    it('reads disk from config', function () {
        $collection = new MediaSlot('docs');

        expect($collection->disk)->toBe('public');
    });

    it('reads pathTemplate from config', function () {
        $collection = new MediaSlot('docs');

        expect($collection->pathTemplate)->toBe('{model_type}/{model_id}');
    });

    it('initialises empty defaults', function () {
        $collection = new MediaSlot('gallery');

        expect($collection->sharingMode)->toBe(MediaSlot::SHARING_SHARED);
        expect($collection->slotSizeLimit)->toBeNull();
        expect($collection->acceptedMimeTypes)->toBe([]);
        expect($collection->fileAcceptor)->toBeNull();
        expect($collection->maxFileSize)->toBeNull();
        expect($collection->isPublic)->toBeFalse();
        expect($collection->isSingleFile)->toBeFalse();
        expect($collection->fallbackUrls)->toBe([]);
        expect($collection->fallbackPaths)->toBe([]);
        expect($collection->defaultTags)->toBe([]);
        expect($collection->conversions)->toBe([]);
    });
});

describe('sharing mode', function () {

    it('marks slots as shared', function () {
        $collection = (new MediaSlot('gallery'))->shared();

        expect($collection->sharingMode)->toBe(MediaSlot::SHARING_SHARED)
            ->and($collection->isShared())->toBeTrue()
            ->and($collection->isExclusive())->toBeFalse();
    });

    it('marks slots as exclusive', function () {
        $collection = (new MediaSlot('avatar'))->exclusive();

        expect($collection->sharingMode)->toBe(MediaSlot::SHARING_EXCLUSIVE)
            ->and($collection->isExclusive())->toBeTrue()
            ->and($collection->isShared())->toBeFalse();
    });
});

/* =================================================================
 * Disk & Storage
 * ================================================================= */

describe('useDisk', function () {

    it('sets the disk', function () {
        $collection = (new MediaSlot('files'))->useDisk('s3');

        expect($collection->disk)->toBe('s3');
    });

    it('returns the slot for chaining', function () {
        $collection = new MediaSlot('files');
        $result = $collection->useDisk('s3');

        expect($result)->toBe($collection);
    });
});

describe('path', function () {

    it('sets the path template', function () {
        $collection = (new MediaSlot('files'))->path('custom/{id}/media');

        expect($collection->pathTemplate)->toBe('custom/{id}/media');
    });
});

describe('isPublic', function () {

    it('sets isPublic to true by default', function () {
        $collection = (new MediaSlot('files'))->isPublic();

        expect($collection->isPublic)->toBeTrue();
    });

    it('accepts an explicit false argument', function () {
        $collection = (new MediaSlot('files'))->isPublic(false);

        expect($collection->isPublic)->toBeFalse();
    });
});

/* =================================================================
 * Slot Size Limits
 * ================================================================= */

describe('singleFile', function () {

    it('sets isSingleFile to true', function () {
        $collection = (new MediaSlot('avatar'))->singleFile();

        expect($collection->isSingleFile)->toBeTrue();
    });

    it('sets slotSizeLimit to 1', function () {
        $collection = (new MediaSlot('avatar'))->singleFile();

        expect($collection->slotSizeLimit)->toBe(1);
    });
});

describe('onlyKeepLatest', function () {

    it('sets slotSizeLimit to the given value', function () {
        $collection = (new MediaSlot('gallery'))->onlyKeepLatest(5);

        expect($collection->slotSizeLimit)->toBe(5);
    });

    it('enforces a minimum of 1', function () {
        $collection = (new MediaSlot('gallery'))->onlyKeepLatest(0);

        expect($collection->slotSizeLimit)->toBe(1);
    });

    it('enforces minimum of 1 for negative values', function () {
        $collection = (new MediaSlot('gallery'))->onlyKeepLatest(-10);

        expect($collection->slotSizeLimit)->toBe(1);
    });
});

/* =================================================================
 * Validation
 * ================================================================= */

describe('acceptsMimeTypes', function () {

    it('sets the accepted MIME types array', function () {
        $mimes = ['image/jpeg', 'image/png'];
        $collection = (new MediaSlot('photos'))->acceptsMimeTypes($mimes);

        expect($collection->acceptedMimeTypes)->toBe($mimes);
    });
});

describe('acceptsFile', function () {

    it('stores the file acceptor closure', function () {
        $callback = fn () => true;
        $collection = (new MediaSlot('files'))->acceptsFile($callback);

        expect($collection->fileAcceptor)->toBe($callback);
    });
});

describe('maxFileSize', function () {

    it('sets the maximum file size in bytes', function () {
        $collection = (new MediaSlot('uploads'))->maxFileSize(5 * 1024 * 1024);

        expect($collection->maxFileSize)->toBe(5242880);
    });
});

/* =================================================================
 * Fallbacks
 * ================================================================= */

describe('useFallbackUrl', function () {

    it('stores a default fallback URL', function () {
        $collection = (new MediaSlot('avatar'))->useFallbackUrl('/img/default.png');

        expect($collection->fallbackUrls)->toBe(['' => '/img/default.png']);
    });

    it('stores a conversion-specific fallback URL', function () {
        $collection = (new MediaSlot('avatar'))
            ->useFallbackUrl('/img/default.png')
            ->useFallbackUrl('/img/thumb-default.png', 'thumb');

        expect($collection->fallbackUrls)->toBe([
            '' => '/img/default.png',
            'thumb' => '/img/thumb-default.png',
        ]);
    });
});

describe('useFallbackPath', function () {

    it('stores a default fallback path', function () {
        $collection = (new MediaSlot('avatar'))->useFallbackPath('/storage/defaults/avatar.png');

        expect($collection->fallbackPaths)->toBe(['' => '/storage/defaults/avatar.png']);
    });

    it('stores a conversion-specific fallback path', function () {
        $collection = (new MediaSlot('avatar'))
            ->useFallbackPath('/storage/defaults/avatar.png')
            ->useFallbackPath('/storage/defaults/avatar-thumb.png', 'thumb');

        expect($collection->fallbackPaths)->toBe([
            '' => '/storage/defaults/avatar.png',
            'thumb' => '/storage/defaults/avatar-thumb.png',
        ]);
    });
});

describe('getFallbackUrl', function () {

    it('returns the default fallback URL when no conversion specified', function () {
        $collection = (new MediaSlot('avatar'))->useFallbackUrl('/img/default.png');

        expect($collection->getFallbackUrl())->toBe('/img/default.png');
    });

    it('returns conversion-specific fallback URL when available', function () {
        $collection = (new MediaSlot('avatar'))
            ->useFallbackUrl('/img/default.png')
            ->useFallbackUrl('/img/thumb.png', 'thumb');

        expect($collection->getFallbackUrl('thumb'))->toBe('/img/thumb.png');
    });

    it('falls back to default URL when conversion has no specific fallback', function () {
        $collection = (new MediaSlot('avatar'))->useFallbackUrl('/img/default.png');

        expect($collection->getFallbackUrl('thumb'))->toBe('/img/default.png');
    });

    it('returns empty string when no fallbacks defined', function () {
        $collection = new MediaSlot('avatar');

        expect($collection->getFallbackUrl())->toBe('');
        expect($collection->getFallbackUrl('thumb'))->toBe('');
    });
});

describe('getFallbackPath', function () {

    it('returns the default fallback path when no conversion specified', function () {
        $collection = (new MediaSlot('avatar'))->useFallbackPath('/storage/default.png');

        expect($collection->getFallbackPath())->toBe('/storage/default.png');
    });

    it('returns conversion-specific fallback path when available', function () {
        $collection = (new MediaSlot('avatar'))
            ->useFallbackPath('/storage/default.png')
            ->useFallbackPath('/storage/thumb.png', 'thumb');

        expect($collection->getFallbackPath('thumb'))->toBe('/storage/thumb.png');
    });

    it('falls back to default path when conversion has no specific fallback', function () {
        $collection = (new MediaSlot('avatar'))->useFallbackPath('/storage/default.png');

        expect($collection->getFallbackPath('medium'))->toBe('/storage/default.png');
    });

    it('returns empty string when no fallbacks defined', function () {
        $collection = new MediaSlot('avatar');

        expect($collection->getFallbackPath())->toBe('');
    });
});

/* =================================================================
 * Tags
 * ================================================================= */

describe('withTags', function () {

    it('sets default tags', function () {
        $collection = (new MediaSlot('gallery'))->withTags(['featured', 'homepage']);

        expect($collection->defaultTags)->toBe(['featured', 'homepage']);
    });
});

/* =================================================================
 * Conversions
 * ================================================================= */

describe('withConversions', function () {

    it('creates ConversionDefinition from simple array shorthand', function () {
        $collection = (new MediaSlot('photos'))->withConversions([
            'thumb' => [150, 150],
        ]);

        $definitions = $collection->getConversionDefinitions();

        expect($definitions)->toHaveKey('thumb');
        expect($definitions['thumb'])->toBeInstanceOf(ConversionDefinition::class);
        expect($definitions['thumb']->name)->toBe('thumb');
        expect($definitions['thumb']->targetWidth)->toBe(150);
        expect($definitions['thumb']->targetHeight)->toBe(150);
    });

    it('creates ConversionDefinition from detailed array shorthand', function () {
        $collection = (new MediaSlot('photos'))->withConversions([
            'preview' => ['width' => 600, 'height' => 400, 'quality' => 80, 'format' => 'webp'],
        ]);

        $definitions = $collection->getConversionDefinitions();

        expect($definitions['preview']->targetWidth)->toBe(600);
        expect($definitions['preview']->targetHeight)->toBe(400);
        expect($definitions['preview']->targetQuality)->toBe(80);
        expect($definitions['preview']->outputFormat)->toBe('webp');
    });

    it('accepts pre-built ConversionDefinition instances', function () {
        $definition = new ConversionDefinition('banner');
        $definition->width(1200)->height(400)->format('webp');

        $collection = (new MediaSlot('banners'))->withConversions([
            'banner' => $definition,
        ]);

        $definitions = $collection->getConversionDefinitions();

        expect($definitions['banner'])->toBe($definition);
        expect($definitions['banner']->targetWidth)->toBe(1200);
    });

    it('handles multiple conversions at once', function () {
        $collection = (new MediaSlot('photos'))->withConversions([
            'thumb' => [150, 150],
            'medium' => [600, 400],
            'large' => [1200, 800],
        ]);

        expect($collection->getConversionDefinitions())->toHaveCount(3);
    });

    it('handles fit option in shorthand', function () {
        $collection = (new MediaSlot('photos'))->withConversions([
            'cover' => ['width' => 300, 'height' => 200, 'fit' => 'crop'],
        ]);

        $def = $collection->getConversionDefinitions()['cover'];

        expect($def->fitMethod)->toBe('crop');
        expect($def->fitWidth)->toBe(300);
        expect($def->fitHeight)->toBe(200);
    });
});

describe('addConversion', function () {

    it('creates a definition via callback', function () {
        $collection = (new MediaSlot('photos'))->addConversion('thumb', function (ConversionDefinition $def) {
            $def->width(150)->height(150)->quality(85);
        });

        $definitions = $collection->getConversionDefinitions();

        expect($definitions)->toHaveKey('thumb');
        expect($definitions['thumb']->targetWidth)->toBe(150);
        expect($definitions['thumb']->targetHeight)->toBe(150);
        expect($definitions['thumb']->targetQuality)->toBe(85);
    });

    it('returns the slot for chaining', function () {
        $collection = new MediaSlot('photos');
        $result = $collection->addConversion('thumb', function (ConversionDefinition $def) {
            $def->width(100);
        });

        expect($result)->toBe($collection);
    });
});

describe('getConversionDefinitions', function () {

    it('returns an empty array when no conversions are registered', function () {
        $collection = new MediaSlot('docs');

        expect($collection->getConversionDefinitions())->toBe([]);
    });

    it('returns all registered conversion definitions', function () {
        $collection = (new MediaSlot('photos'))
            ->withConversions(['thumb' => [150, 150]])
            ->addConversion('large', function (ConversionDefinition $def) {
                $def->width(1200);
            });

        expect($collection->getConversionDefinitions())->toHaveCount(2);
        expect($collection->getConversionDefinitions())->toHaveKeys(['thumb', 'large']);
    });
});

/* =================================================================
 * Fluent Chaining
 * ================================================================= */

describe('fluent chaining', function () {

    it('supports full method chain', function () {
        $collection = (new MediaSlot('avatar'))
            ->useDisk('s3')
            ->path('users/{id}/avatar')
            ->isPublic()
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png'])
            ->maxFileSize(2 * 1024 * 1024)
            ->useFallbackUrl('/img/default-avatar.png')
            ->useFallbackPath('/storage/defaults/avatar.png')
            ->withTags(['profile'])
            ->withConversions(['thumb' => [100, 100]]);

        expect($collection->name)->toBe('avatar');
        expect($collection->disk)->toBe('s3');
        expect($collection->pathTemplate)->toBe('users/{id}/avatar');
        expect($collection->isPublic)->toBeTrue();
        expect($collection->isSingleFile)->toBeTrue();
        expect($collection->slotSizeLimit)->toBe(1);
        expect($collection->acceptedMimeTypes)->toBe(['image/jpeg', 'image/png']);
        expect($collection->maxFileSize)->toBe(2097152);
        expect($collection->getFallbackUrl())->toBe('/img/default-avatar.png');
        expect($collection->getFallbackPath())->toBe('/storage/defaults/avatar.png');
        expect($collection->defaultTags)->toBe(['profile']);
        expect($collection->getConversionDefinitions())->toHaveCount(1);
    });
});

/* =================================================================
 * Original File Optimization
 * ================================================================= */

describe('convertTo', function () {

    it('sets the target format for original file conversion', function () {
        $collection = (new MediaSlot('gallery'))->convertTo('webp');

        expect($collection->convertFormat)->toBe('webp');
    });

    it('returns the slot for chaining', function () {
        $collection = new MediaSlot('gallery');
        $result = $collection->convertTo('avif');

        expect($result)->toBe($collection);
    });
});

describe('withQuality', function () {

    it('sets the quality for original file conversion', function () {
        $collection = (new MediaSlot('gallery'))->withQuality(95);

        expect($collection->convertQuality)->toBe(95);
    });

    it('clamps quality to 0-100 range', function () {
        $low = (new MediaSlot('a'))->withQuality(-10);
        $high = (new MediaSlot('b'))->withQuality(150);

        expect($low->convertQuality)->toBe(0);
        expect($high->convertQuality)->toBe(100);
    });
});

describe('maxSize', function () {

    it('sets the max pixel size for the longest edge', function () {
        $collection = (new MediaSlot('gallery'))->maxSize(2400);

        expect($collection->convertMaxSize)->toBe(2400);
    });

    it('enforces minimum of 1', function () {
        $collection = (new MediaSlot('gallery'))->maxSize(0);

        expect($collection->convertMaxSize)->toBe(1);
    });
});

describe('shouldConvertOriginal', function () {

    it('returns false when no optimization is configured', function () {
        $collection = new MediaSlot('docs');

        expect($collection->shouldConvertOriginal())->toBeFalse();
    });

    it('returns true when format is set', function () {
        $collection = (new MediaSlot('gallery'))->convertTo('webp');

        expect($collection->shouldConvertOriginal())->toBeTrue();
    });

    it('returns true when quality is set', function () {
        $collection = (new MediaSlot('gallery'))->withQuality(85);

        expect($collection->shouldConvertOriginal())->toBeTrue();
    });

    it('returns true when maxSize is set', function () {
        $collection = (new MediaSlot('gallery'))->maxSize(1400);

        expect($collection->shouldConvertOriginal())->toBeTrue();
    });

    it('returns true when all three are set', function () {
        $collection = (new MediaSlot('gallery'))
            ->convertTo('webp')
            ->withQuality(95)
            ->maxSize(2400);

        expect($collection->shouldConvertOriginal())->toBeTrue();
    });
});

describe('original optimization chaining', function () {

    it('chains with all other slot methods', function () {
        $collection = (new MediaSlot('products'))
            ->singleFile()
            ->shared()
            ->isPublic()
            ->path('products/{model_id}')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->convertTo('webp')
            ->withQuality(95)
            ->maxSize(2400);

        expect($collection->isSingleFile)->toBeTrue();
        expect($collection->isPublic)->toBeTrue();
        expect($collection->convertFormat)->toBe('webp');
        expect($collection->convertQuality)->toBe(95);
        expect($collection->convertMaxSize)->toBe(2400);
        expect($collection->shouldConvertOriginal())->toBeTrue();
    });
});
