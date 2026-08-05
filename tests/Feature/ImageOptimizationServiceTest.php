<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Services\ImageOptimizationService;
use Nvl\Media\Services\MediaImageTransformer;
use Nvl\Media\Services\MediaTemporaryFileRegistry;
use Nvl\Media\Slots\MediaSlot;

function createOptimizationService(?MediaImageTransformer $imageTransformer = null): ImageOptimizationService
{
    return new ImageOptimizationService(
        $imageTransformer ?? app(MediaImageTransformer::class),
        app(MediaTemporaryFileRegistry::class),
    );
}

function createSlotWithOptimization(array $options = []): MediaSlot
{
    $slot = new MediaSlot($options['name'] ?? 'test');

    if (isset($options['convertFormat'])) {
        $slot->convertTo($options['convertFormat']);
    }

    if (isset($options['convertQuality'])) {
        $slot->withQuality($options['convertQuality']);
    }

    if (isset($options['convertMaxSize'])) {
        $slot->maxSize($options['convertMaxSize']);
    }

    return $slot;
}

describe('shouldOptimize', function () {

    it('returns false when slot has no optimization settings', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $slot = new MediaSlot('plain');

        expect($service->shouldOptimize($file, $slot))->toBeFalse();
    });

    it('returns true when slot has convertFormat', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $slot = createSlotWithOptimization(['convertFormat' => 'webp']);

        expect($service->shouldOptimize($file, $slot))->toBeTrue();
    });

    it('returns true when slot has convertQuality', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $slot = createSlotWithOptimization(['convertQuality' => 85]);

        expect($service->shouldOptimize($file, $slot))->toBeTrue();
    });

    it('returns true when slot has convertMaxSize', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $slot = createSlotWithOptimization(['convertMaxSize' => 2400]);

        expect($service->shouldOptimize($file, $slot))->toBeTrue();
    });

    it('returns false for non-image files', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $slot = createSlotWithOptimization(['convertFormat' => 'webp']);

        expect($service->shouldOptimize($file, $slot))->toBeFalse();
    });

    it('returns false for SVG files', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->create('icon.svg', 10, 'image/svg+xml');
        $slot = createSlotWithOptimization(['convertFormat' => 'webp']);

        expect($service->shouldOptimize($file, $slot))->toBeFalse();
    });

    it('returns false for GIF files', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->create('anim.gif', 100, 'image/gif');
        $slot = createSlotWithOptimization(['convertFormat' => 'webp']);

        expect($service->shouldOptimize($file, $slot))->toBeFalse();
    });

    it('returns false for same-format re-encode when only format is set', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->image('photo.webp', 100, 100);
        $slot = createSlotWithOptimization(['convertFormat' => 'webp']);

        expect($service->shouldOptimize($file, $slot))->toBeFalse();
    });

    it('returns true for same format when maxSize is also set', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->image('photo.webp', 100, 100);
        $slot = createSlotWithOptimization(['convertFormat' => 'webp', 'convertMaxSize' => 2400]);

        expect($service->shouldOptimize($file, $slot))->toBeTrue();
    });

    it('returns true for cross-format conversion', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $slot = createSlotWithOptimization(['convertFormat' => 'webp']);

        expect($service->shouldOptimize($file, $slot))->toBeTrue();
    });

    it('respects configurable skip_formats', function () {
        config(['media.optimization.skip_formats' => ['svg', 'gif', 'bmp']]);

        $service = createOptimizationService();
        $file = UploadedFile::fake()->create('photo.bmp', 100, 'image/bmp');
        $slot = createSlotWithOptimization(['convertFormat' => 'webp']);

        expect($service->shouldOptimize($file, $slot))->toBeFalse();
    });
});

describe('wouldReEncodeToSameFormat', function () {

    it('returns true for jpg to jpg', function () {
        $service = createOptimizationService();

        expect($service->wouldReEncodeToSameFormat('jpg', 'jpg'))->toBeTrue();
    });

    it('returns true for webp to webp', function () {
        $service = createOptimizationService();

        expect($service->wouldReEncodeToSameFormat('webp', 'webp'))->toBeTrue();
    });

    it('returns false for jpg to webp', function () {
        $service = createOptimizationService();

        expect($service->wouldReEncodeToSameFormat('jpg', 'webp'))->toBeFalse();
    });

    it('returns false for png to avif', function () {
        $service = createOptimizationService();

        expect($service->wouldReEncodeToSameFormat('png', 'avif'))->toBeFalse();
    });

    it('returns false when target is null', function () {
        $service = createOptimizationService();

        expect($service->wouldReEncodeToSameFormat('jpg', null))->toBeFalse();
    });

});

describe('optimize', function () {

    it('returns original when slot has no optimization', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $slot = new MediaSlot('plain');

        $result = $service->optimize($file, $slot);

        expect($result)->toBe($file)
            ->and(app(MediaTemporaryFileRegistry::class)->count())->toBe(0);
    });

    it('returns original for non-image file', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $slot = createSlotWithOptimization(['convertFormat' => 'webp']);

        $result = $service->optimize($file, $slot);

        expect($result)->toBe($file)
            ->and(app(MediaTemporaryFileRegistry::class)->count())->toBe(0);
    });

    it('returns original for SVG regardless of config', function () {
        $service = createOptimizationService();
        $file = UploadedFile::fake()->create('icon.svg', 10, 'image/svg+xml');
        $slot = createSlotWithOptimization(['convertFormat' => 'webp', 'convertMaxSize' => 500]);

        $result = $service->optimize($file, $slot);

        expect($result)->toBe($file)
            ->and(app(MediaTemporaryFileRegistry::class)->count())->toBe(0);
    });

    it('logs warning when optimization fails', function () {
        // Use a mock processor that throws
        $processor = Mockery::mock(MediaImageTransformer::class);
        $processor->shouldReceive('process')->andThrow(new RuntimeException('test failure'));

        $service = createOptimizationService($processor);
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $slot = createSlotWithOptimization(['convertFormat' => 'webp']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'Image optimization failed'));

        $result = $service->optimize($file, $slot);

        expect($result)->toBe($file)
            ->and(app(MediaTemporaryFileRegistry::class)->count())->toBe(0);
    });

    it('throws FileUnacceptableForCollection when optimization fails and original exceeds maxSize', function () {
        $processor = Mockery::mock(MediaImageTransformer::class);
        $processor->shouldReceive('process')->andThrow(new RuntimeException('processor crashed'));

        $service = createOptimizationService($processor);
        // Create a fake image that reports as 4000x3000
        $file = UploadedFile::fake()->image('big-photo.jpg', 4000, 3000);
        $slot = createSlotWithOptimization(['convertMaxSize' => 2400]);

        $service->optimize($file, $slot);
    })->throws(FileUnacceptableForCollection::class);

    it('returns original when optimization fails and original is within maxSize', function () {
        $processor = Mockery::mock(MediaImageTransformer::class);
        $processor->shouldReceive('process')->andThrow(new RuntimeException('processor crashed'));

        $service = createOptimizationService($processor);
        $file = UploadedFile::fake()->image('small-photo.jpg', 800, 600);
        $slot = createSlotWithOptimization(['convertMaxSize' => 2400]);

        $result = $service->optimize($file, $slot);

        expect($result)->toBe($file);
    });
});

describe('MimeType optimization helpers', function () {

    it('isRasterOptimizable returns true for raster formats', function () {
        expect(MimeType::Jpg->isRasterOptimizable())->toBeTrue()
            ->and(MimeType::Png->isRasterOptimizable())->toBeTrue()
            ->and(MimeType::Webp->isRasterOptimizable())->toBeTrue()
            ->and(MimeType::Avif->isRasterOptimizable())->toBeTrue()
            ->and(MimeType::Bmp->isRasterOptimizable())->toBeTrue();
    });

    it('isRasterOptimizable returns false for non-raster formats', function () {
        expect(MimeType::Svg->isRasterOptimizable())->toBeFalse()
            ->and(MimeType::Gif->isRasterOptimizable())->toBeFalse()
            ->and(MimeType::Mp4->isRasterOptimizable())->toBeFalse()
            ->and(MimeType::Pdf->isRasterOptimizable())->toBeFalse();
    });

    it('isLossyReEncode returns true for same format', function () {
        expect(MimeType::Jpg->isLossyReEncode(MimeType::Jpg))->toBeTrue()
            ->and(MimeType::Webp->isLossyReEncode(MimeType::Webp))->toBeTrue()
            ->and(MimeType::Avif->isLossyReEncode(MimeType::Avif))->toBeTrue();
    });

    it('isLossyReEncode returns false for different formats', function () {
        expect(MimeType::Jpg->isLossyReEncode(MimeType::Webp))->toBeFalse()
            ->and(MimeType::Png->isLossyReEncode(MimeType::Avif))->toBeFalse()
            ->and(MimeType::Webp->isLossyReEncode(MimeType::Jpg))->toBeFalse();
    });
});
