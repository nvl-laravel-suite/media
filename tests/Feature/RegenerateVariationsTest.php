<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Bus;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Jobs\RegenerateMediaVariationsJob;
use Nvl\Media\Models\Media;

describe('RegenerateMediaVariationsJob', function () {
    it('dispatches GenerateImageVariationJob for each enabled preset', function () {
        Bus::fake([GenerateImageVariationJob::class]);
        config(['nvl-media.image_variation_presets' => ['thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true], 'large' => ['width' => 1280, 'height' => 1280, 'quality' => 85, 'format' => 'webp', 'enabled' => true], 'disabled' => ['width' => 50, 'height' => 50, 'quality' => 50, 'format' => 'webp', 'enabled' => false]]]);
        // Create a test media record
        $media = Media::create(['filename' => 'regen-test.jpg', 'hash' => 'regen-hash-'.uniqid().'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'public', 'folder' => 'test', 'is_public' => true, 'type' => MediaType::IMAGE, 'digest' => md5(uniqid())]);
        $job = new RegenerateMediaVariationsJob;
        app()->call([$job, 'handle']);
        // 2 enabled presets × 1 media = 2 dispatches (disabled preset excluded)
        Bus::assertDispatched(GenerateImageVariationJob::class, 2);
    });
    it('filters to specific preset names when provided', function () {
        Bus::fake([GenerateImageVariationJob::class]);
        config(['nvl-media.image_variation_presets' => ['thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true], 'medium' => ['width' => 640, 'height' => 640, 'quality' => 85, 'format' => 'webp', 'enabled' => true], 'large' => ['width' => 1280, 'height' => 1280, 'quality' => 85, 'format' => 'webp', 'enabled' => true]]]);
        $media = Media::create(['filename' => 'regen-filter.jpg', 'hash' => 'regen-filter-'.uniqid().'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'public', 'folder' => 'test', 'is_public' => true, 'type' => MediaType::IMAGE, 'digest' => md5(uniqid())]);
        $job = new RegenerateMediaVariationsJob(presetNames: ['thumb']);
        app()->call([$job, 'handle']);
        // Only 'thumb' preset = 1 dispatch
        Bus::assertDispatched(GenerateImageVariationJob::class, 1);
    });
    it('skips non-image media types', function () {
        Bus::fake([GenerateImageVariationJob::class]);
        config(['nvl-media.image_variation_presets' => ['thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true]]]);
        // Create a document media record — should not generate variations
        Media::create(['filename' => 'doc.pdf', 'hash' => 'doc-hash-'.uniqid().'.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size' => 2048, 'disk' => 'public', 'folder' => 'test', 'is_public' => true, 'type' => MediaType::DOCUMENT, 'digest' => md5(uniqid())]);
        // Default type filter is IMAGE — document should not match
        $job = new RegenerateMediaVariationsJob;
        app()->call([$job, 'handle']);
        Bus::assertNotDispatched(GenerateImageVariationJob::class);
    });
    it('processes one chunk per run and continues from its last id in a new job', function () {
        Bus::fake([GenerateImageVariationJob::class, RegenerateMediaVariationsJob::class]);
        config(['nvl-media.image_variation_presets' => ['thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true]]]);
        foreach (range(1, 3) as $index) {
            Media::create(['filename' => "chunk-{$index}.jpg", 'hash' => "chunk-{$index}-".uniqid().'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'public', 'folder' => 'test', 'is_public' => true, 'type' => MediaType::IMAGE, 'digest' => md5(uniqid())]);
        }
        $job = new RegenerateMediaVariationsJob(chunkSize: 2);

        app()->call([$job, 'handle']);
        Bus::assertDispatched(GenerateImageVariationJob::class, 2);
        $continuations = Bus::dispatched(RegenerateMediaVariationsJob::class);
        expect($continuations)->toHaveCount(1);

        app()->call([$continuations->first(), 'handle']);
        Bus::assertDispatched(GenerateImageVariationJob::class, 3);
        Bus::assertDispatched(RegenerateMediaVariationsJob::class, 1);
        expect(Bus::dispatched(GenerateImageVariationJob::class)->pluck('mediaId')->all())->toEqualCanonicalizing(Media::query()->pluck('id')->all());
    });
    it('keeps its continuation inside the batch and stops once the batch is cancelled', function () {
        Bus::fake([GenerateImageVariationJob::class, RegenerateMediaVariationsJob::class]);
        config(['nvl-media.image_variation_presets' => ['thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true]]]);
        foreach (range(1, 2) as $index) {
            Media::create(['filename' => "batch-{$index}.jpg", 'hash' => "batch-{$index}-".uniqid().'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'public', 'folder' => 'test', 'is_public' => true, 'type' => MediaType::IMAGE, 'digest' => md5(uniqid())]);
        }
        [$job, $batch] = (new RegenerateMediaVariationsJob(chunkSize: 1))->withFakeBatch();

        app()->call([$job, 'handle']);
        Bus::assertDispatched(GenerateImageVariationJob::class, 1);
        Bus::assertNotDispatched(RegenerateMediaVariationsJob::class);
        expect($batch->added)->toHaveCount(1)->and($batch->added[0])->toBeInstanceOf(RegenerateMediaVariationsJob::class);

        [$continuation] = $batch->added[0]->withFakeBatch(cancelledAt: now()->toImmutable());
        app()->call([$continuation, 'handle']);
        Bus::assertDispatched(GenerateImageVariationJob::class, 1);
    });
    it('skips sources the GD driver can never decode', function () {
        Bus::fake([GenerateImageVariationJob::class]);
        config(['nvl-media.image_driver' => 'gd', 'nvl-media.image_variation_presets' => ['thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true]]]);
        Media::create(['filename' => 'logo.svg', 'hash' => 'logo-'.uniqid().'.svg', 'extension' => 'svg', 'mime_type' => 'image/svg+xml', 'size' => 1024, 'disk' => 'public', 'folder' => 'test', 'is_public' => true, 'type' => MediaType::IMAGE, 'digest' => md5(uniqid())]);
        app()->call([new RegenerateMediaVariationsJob, 'handle']);
        Bus::assertNotDispatched(GenerateImageVariationJob::class);
    });
    it('does not dispatch work for media that has not passed the scanner boundary', function () {
        Bus::fake([GenerateImageVariationJob::class]);
        config(['nvl-media.image_variation_presets' => ['thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true]]]);
        Media::create(['filename' => 'quarantined.jpg', 'hash' => 'quarantined-'.uniqid().'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'public', 'folder' => 'test', 'is_public' => false, 'type' => MediaType::IMAGE, 'status' => MediaLifecycleStatus::Quarantined, 'available_at' => null, 'digest' => md5(uniqid())]);
        app()->call([new RegenerateMediaVariationsJob, 'handle']);
        Bus::assertNotDispatched(GenerateImageVariationJob::class);
    });
    it('filters by disk when provided', function () {
        Bus::fake([GenerateImageVariationJob::class]);
        config(['nvl-media.image_variation_presets' => ['thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true]]]);
        // Create media on different disks
        Media::create(['filename' => 'public-img.jpg', 'hash' => 'pub-hash-'.uniqid().'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'public', 'folder' => 'test', 'is_public' => true, 'type' => MediaType::IMAGE, 'digest' => md5(uniqid())]);
        Media::create(['filename' => 'local-img.jpg', 'hash' => 'loc-hash-'.uniqid().'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'local', 'folder' => 'test', 'is_public' => false, 'type' => MediaType::IMAGE, 'digest' => md5(uniqid())]);
        $job = new RegenerateMediaVariationsJob(disk: 'public');
        app()->call([$job, 'handle']);
        // Only public disk media = 1 dispatch
        Bus::assertDispatched(GenerateImageVariationJob::class, 1);
    });
    it('dispatches nothing when no presets are enabled', function () {
        Bus::fake([GenerateImageVariationJob::class]);
        config(['nvl-media.image_variation_presets' => ['thumb' => ['width' => 150, 'height' => 150, 'enabled' => false]]]);
        Media::create(['filename' => 'no-presets.jpg', 'hash' => 'no-presets-'.uniqid().'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'public', 'folder' => 'test', 'is_public' => true, 'type' => MediaType::IMAGE, 'digest' => md5(uniqid())]);
        $job = new RegenerateMediaVariationsJob;
        app()->call([$job, 'handle']);
        Bus::assertNotDispatched(GenerateImageVariationJob::class);
    });
});
describe('ConversionDefinition enabled flag', function () {
    it('defaults to enabled', function () {
        $definition = new ConversionDefinition('thumb');
        expect($definition->enabled)->toBeTrue();
    });
    it('can be disabled via disabled()', function () {
        $definition = new ConversionDefinition('thumb');
        $definition->disabled();
        expect($definition->enabled)->toBeFalse();
    });
    it('can be toggled via enabled()', function () {
        $definition = new ConversionDefinition('thumb');
        $definition->enabled(false);
        expect($definition->enabled)->toBeFalse();
        $definition->enabled(true);
        expect($definition->enabled)->toBeTrue();
    });
    it('returns $this for chaining', function () {
        $definition = new ConversionDefinition('thumb');
        $result = $definition->width(150)->height(150)->disabled();
        expect($result)->toBeInstanceOf(ConversionDefinition::class)->and($result->enabled)->toBeFalse();
    });
});
