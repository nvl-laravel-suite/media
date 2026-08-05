<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Jobs\ProcessMediaVariationsJob;
use Nvl\Media\Models\Media;

beforeEach(function () {
    config([
        'media.root_folder' => '',
        'media.conversions_folder' => 'conversions',
        'media.queue.enabled' => true,
        'media.queue.connection' => 'database',
        'media.queue.name' => 'media',
        'media.auto_generate_variations' => true,
        'media.image_variation_presets' => [
            'thumb' => ['width' => 150, 'height' => 150, 'quality' => 80, 'format' => 'webp', 'enabled' => true],
            'large' => ['width' => 1280, 'height' => 1280, 'quality' => 85, 'format' => 'webp', 'enabled' => false],
        ],
        'media.output_conversion' => ['enabled' => false],
    ]);
});

describe('GenerateImageVariationJob', function () {

    it('is dispatchable to the configured queue', function () {
        Queue::fake();

        GenerateImageVariationJob::dispatch('media-123', 'thumb', ['width' => 150, 'height' => 150]);

        Queue::assertPushed(GenerateImageVariationJob::class, function ($job) {
            return $job->queue === 'media';
        });
    });

    it('skips when media not found', function () {
        Log::spy();

        $job = new GenerateImageVariationJob('00000000-0000-0000-0000-000000000000', 'thumb', ['width' => 150]);

        app()->call([$job, 'handle']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'GenerateImageVariationJob: media not found, skipping.'
                    && $context['media_id'] === '00000000-0000-0000-0000-000000000000';
            });
    });

    it('provides meaningful tags', function () {
        $job = new GenerateImageVariationJob('media-123', 'thumb', ['width' => 150]);

        expect($job->tags())->toBe(['media-variation', 'media:media-123', 'preset:thumb']);
    });

    it('uses media id and preset name as its unique queue identity', function () {
        $job = new GenerateImageVariationJob('media-123', 'thumb', ['width' => 150]);

        expect($job)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->uniqueId())->toBe('media-123:1:thumb');
    });

    it('preserves the complete conversion definition through queue serialization', function () {
        $definition = (new ConversionDefinition('card'))
            ->fit('max', 1200, 1200)
            ->format('avif')
            ->quality(60)
            ->sharpen(8)
            ->onQueue('priority-media');

        $job = unserialize(serialize(new GenerateImageVariationJob(
            'media-123',
            'card',
            $definition,
            4,
        )));
        $property = new ReflectionProperty($job, 'definition');
        $restored = $property->getValue($job);

        expect($restored)->toBeInstanceOf(ConversionDefinition::class)
            ->and($restored->fitMethod)->toBe('max')
            ->and($restored->fitWidth)->toBe(1200)
            ->and($restored->fitHeight)->toBe(1200)
            ->and($restored->outputFormat)->toBe('avif')
            ->and($restored->targetQuality)->toBe(60)
            ->and($restored->sharpenAmount)->toBe(8)
            ->and($job->queue)->toBe('priority-media');
    });

    it('uses bounded per-job queue settings', function () {
        config([
            'media.queue.jobs.generate.tries' => 5,
            'media.queue.jobs.generate.timeout' => 240,
            'media.queue.jobs.generate.unique_for' => 900,
            'media.queue.jobs.generate.backoff' => [15, 45],
        ]);

        $job = new GenerateImageVariationJob('media-123', 'thumb', ['width' => 150]);

        expect($job->tries)->toBe(5)
            ->and($job->timeout)->toBe(240)
            ->and($job->uniqueFor)->toBe(900)
            ->and($job->backoff())->toBe([15, 45]);
    });
});

describe('ProcessMediaVariationsJob', function () {

    it('dispatches individual variation jobs for each enabled preset', function () {
        Queue::fake();

        $media = new Media;
        $media->id = 'test-uuid';
        $media->type = MediaType::IMAGE;
        $media->extension = 'jpg';
        $media->exists = true;
        $media->save = fn () => true;

        // Create a real media record for the job to find
        $created = Media::create([
            'filename' => 'test.jpg',
            'hash' => md5(uniqid()).'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'disk' => 'local',
            'folder' => 'test',
            'is_public' => true,
            'type' => MediaType::IMAGE,
            'digest' => md5('test'),
        ]);

        ProcessMediaVariationsJob::dispatch($created->id);

        Queue::assertPushed(ProcessMediaVariationsJob::class);
    });

    it('provides meaningful tags', function () {
        $job = new ProcessMediaVariationsJob('media-456');

        expect($job->tags())->toBe(['media-variations', 'media:media-456']);
    });
});
