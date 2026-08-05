<?php

declare(strict_types=1);

use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Exceptions\ConversionFailedException;
use Nvl\Media\Services\MediaImageTransformer;

beforeEach(function () {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('The GD extension is required to generate transformer fixtures.');
    }
});

function imageTransformer(): MediaImageTransformer
{
    return app(MediaImageTransformer::class);
}

function createTestImage(int $width = 800, int $height = 600, string $color = '#ff0000'): string
{
    $path = tempnam(sys_get_temp_dir(), 'media_test_').'.jpg';
    $image = imagecreatetruecolor($width, $height);
    $red = hexdec(substr($color, 1, 2));
    $green = hexdec(substr($color, 3, 2));
    $blue = hexdec(substr($color, 5, 2));
    $fill = imagecolorallocate($image, $red, $green, $blue);

    if ($fill === false) {
        throw new RuntimeException('Unable to allocate GD image color.');
    }

    imagefill($image, 0, 0, $fill);
    imagejpeg($image, $path, 90);
    imagedestroy($image);

    return $path;
}

function outputPath(string $suffix = ''): string
{
    $dir = sys_get_temp_dir().'/media_test_output';

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir.'/output_'.uniqid().$suffix;
}

afterEach(function () {
    // Clean up temp output directory
    $dir = sys_get_temp_dir().'/media_test_output';

    if (is_dir($dir)) {
        $files = glob($dir.'/*') ?: [];
        foreach ($files as $f) {
            @unlink($f);
        }
    }
});

/* =================================================================
 * Basic resize
 * ================================================================= */

describe('resize', function () {

    it('resizes to target width preserving aspect ratio', function () {
        $source = createTestImage(800, 600);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(400);

        $result = imageTransformer()->process($source, $output, $definition);

        expect($result['width'])->toBe(400)
            ->and($result['height'])->toBe(300)
            ->and(file_exists($output))->toBeTrue()
            ->and($result['size'])->toBeGreaterThan(0);

        @unlink($source);
    });

    it('resizes to target height preserving aspect ratio', function () {
        $source = createTestImage(800, 600);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->height(300);

        $result = imageTransformer()->process($source, $output, $definition);

        expect($result['width'])->toBe(400)
            ->and($result['height'])->toBe(300);

        @unlink($source);
    });

    it('resizes to both width and height', function () {
        $source = createTestImage(800, 600);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(200)->height(200);

        $result = imageTransformer()->process($source, $output, $definition);

        // With aspect ratio constraint, fits within 200x200
        expect($result['width'])->toBeLessThanOrEqual(200)
            ->and($result['height'])->toBeLessThanOrEqual(200);

        @unlink($source);
    });

    it('does not upsize images beyond original dimensions', function () {
        $source = createTestImage(100, 80);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(500)->height(500);

        $result = imageTransformer()->process($source, $output, $definition);

        expect($result['width'])->toBeLessThanOrEqual(100)
            ->and($result['height'])->toBeLessThanOrEqual(80);

        @unlink($source);
    });
});

/* =================================================================
 * Crop
 * ================================================================= */

describe('crop', function () {

    it('crops to exact dimensions', function () {
        $source = createTestImage(800, 600);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->crop(200, 200);

        $result = imageTransformer()->process($source, $output, $definition);

        expect($result['width'])->toBe(200)
            ->and($result['height'])->toBe(200);

        @unlink($source);
    });

    it('crop takes priority over resize', function () {
        $source = createTestImage(800, 600);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(400)->height(400)->crop(150, 100);

        $result = imageTransformer()->process($source, $output, $definition);

        expect($result['width'])->toBe(150)
            ->and($result['height'])->toBe(100);

        @unlink($source);
    });
});

/* =================================================================
 * Fit methods
 * ================================================================= */

describe('fit', function () {

    it('fit crop resizes and crops to exact dimensions', function () {
        $source = createTestImage(800, 600);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->fit('crop', 200, 200);

        $result = imageTransformer()->process($source, $output, $definition);

        expect($result['width'])->toBe(200)
            ->and($result['height'])->toBe(200);

        @unlink($source);
    });

    it('fit contain preserves aspect ratio within bounds', function () {
        $source = createTestImage(800, 600);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->fit('contain', 200, 200);

        $result = imageTransformer()->process($source, $output, $definition);

        expect($result['width'])->toBeLessThanOrEqual(200)
            ->and($result['height'])->toBeLessThanOrEqual(200);

        @unlink($source);
    });

    it('fit stretch ignores aspect ratio', function () {
        $source = createTestImage(800, 600);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->fit('stretch', 200, 100);

        $result = imageTransformer()->process($source, $output, $definition);

        expect($result['width'])->toBe(200)
            ->and($result['height'])->toBe(100);

        @unlink($source);
    });
});

/* =================================================================
 * Format conversion
 * ================================================================= */

describe('format', function () {

    it('converts to webp format', function () {
        $source = createTestImage(200, 200);
        $output = outputPath('.webp');

        $definition = (new ConversionDefinition('test'))
            ->width(200)->format('webp');

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue()
            ->and($result['size'])->toBeGreaterThan(0);

        @unlink($source);
    });

    it('converts to png format', function () {
        $source = createTestImage(200, 200);
        $output = outputPath('.png');

        $definition = (new ConversionDefinition('test'))
            ->width(200)->format('png');

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue()
            ->and($result['size'])->toBeGreaterThan(0);

        @unlink($source);
    });
});

/* =================================================================
 * Quality
 * ================================================================= */

describe('quality', function () {

    it('respects quality setting', function () {
        $source = createTestImage(400, 400);
        $output_high = outputPath('.jpg');
        $output_low = outputPath('.jpg');

        $high_def = (new ConversionDefinition('high'))->width(400)->quality(95);
        $low_def = (new ConversionDefinition('low'))->width(400)->quality(10);

        $high_result = imageTransformer()->process($source, $output_high, $high_def);
        $low_result = imageTransformer()->process($source, $output_low, $low_def);

        expect($high_result['size'])->toBeGreaterThan($low_result['size']);

        @unlink($source);
    });
});

/* =================================================================
 * Effects
 * ================================================================= */

describe('effects', function () {

    it('applies greyscale', function () {
        $source = createTestImage(200, 200);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(200)->greyscale();

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue()
            ->and($result['width'])->toBe(200);

        @unlink($source);
    });

    it('applies blur', function () {
        $source = createTestImage(200, 200);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(200)->blur(15);

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue()
            ->and($result['width'])->toBe(200);

        @unlink($source);
    });

    it('applies sharpen', function () {
        $source = createTestImage(200, 200);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(200)->sharpen(15);

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue()
            ->and($result['width'])->toBe(200);

        @unlink($source);
    });

    it('applies rotation', function () {
        $source = createTestImage(400, 200);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->orientation(90);

        $result = imageTransformer()->process($source, $output, $definition);

        // After 90 degree rotation, dimensions swap
        expect($result['width'])->toBe(200)
            ->and($result['height'])->toBe(400);

        @unlink($source);
    });

    it('applies brightness adjustment', function () {
        $source = createTestImage(200, 200);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(200)->brightness(30);

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue();

        @unlink($source);
    });

    it('applies contrast adjustment', function () {
        $source = createTestImage(200, 200);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(200)->contrast(30);

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue();

        @unlink($source);
    });

    it('applies background color', function () {
        $source = createTestImage(200, 200);
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(200)->background('#ffffff');

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue()
            ->and($result['width'])->toBe(200);

        @unlink($source);
    });
});

/* =================================================================
 * Error handling
 * ================================================================= */

describe('error handling', function () {

    it('throws ConversionFailedException for invalid source', function () {
        $output = outputPath('.jpg');

        $definition = (new ConversionDefinition('test'))
            ->width(200);

        imageTransformer()->process('/nonexistent/file.jpg', $output, $definition);
    })->throws(ConversionFailedException::class);

    it('creates output directory if it does not exist', function () {
        $source = createTestImage(200, 200);
        $nested_dir = sys_get_temp_dir().'/media_test_output/nested_'.uniqid();
        $output = $nested_dir.'/output.jpg';

        $definition = (new ConversionDefinition('test'))
            ->width(100);

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue()
            ->and($result['width'])->toBe(100);

        @unlink($source);
        @unlink($output);
        @rmdir($nested_dir);
    });
});

/* =================================================================
 * No manipulation (pass-through)
 * ================================================================= */

describe('pass-through', function () {

    it('saves image at default quality when no manipulation set', function () {
        $source = createTestImage(300, 200);
        $output = outputPath('.jpg');

        $definition = new ConversionDefinition('test');

        $result = imageTransformer()->process($source, $output, $definition);

        expect(file_exists($output))->toBeTrue()
            ->and($result['width'])->toBe(300)
            ->and($result['height'])->toBe(200);

        @unlink($source);
    });
});
