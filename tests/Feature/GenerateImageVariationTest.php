<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Exceptions\ConversionFailedException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaImageTransformer;

function createVariationUser(array $overrides = []): User
{
    return User::withoutEvents(
        static fn (): User => User::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'yIXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',

        ], $overrides)),
    );
}

function createVariationMedia(array $overrides = []): Media
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
    ], $overrides));
}

/* =================================================================
 * GenerateImageVariationAction
 * ================================================================= */

describe('GenerateImageVariationAction', function () {

    beforeEach(function () {
        Storage::fake('public');

        config([
            'filesystems.default' => 'public',
            'media.conversions_folder' => 'conversions',
        ]);
    });

    it('returns null for non-image media types', function () {
        $media = createVariationMedia(['type' => MediaType::DOCUMENT, 'extension' => 'pdf']);

        $definition = (new ConversionDefinition('thumb'))
            ->width(150)->height(150);

        $action = app(GenerateImageVariationAction::class);
        $result = $action->execute($media, $definition);

        expect($result)->toBeNull();
    });

    it('returns null for video media types', function () {
        $media = createVariationMedia(['type' => MediaType::VIDEO, 'extension' => 'mp4']);

        $definition = (new ConversionDefinition('thumb'))
            ->width(150)->height(150);

        $action = app(GenerateImageVariationAction::class);
        $result = $action->execute($media, $definition);

        expect($result)->toBeNull();
    });

    it('does not transform media that has not passed the scanner boundary', function (MediaLifecycleStatus $status) {
        $media = createVariationMedia([
            'status' => $status,
            'available_at' => null,
        ]);
        Storage::disk('public')->put($media->buildPath(), 'untrusted image content');

        $imageTransformer = Mockery::mock(MediaImageTransformer::class);
        $imageTransformer->shouldNotReceive('process');
        app()->instance(MediaImageTransformer::class, $imageTransformer);

        $definition = (new ConversionDefinition('thumb'))
            ->width(150)->height(150);

        $result = app(GenerateImageVariationAction::class)->execute($media, $definition);

        expect($result)->toBeNull()
            ->and($media->fresh()->status)->toBe($status)
            ->and(MediaImageVariation::query()->where('media_id', $media->id)->exists())->toBeFalse();
    })->with([
        MediaLifecycleStatus::PendingUpload,
        MediaLifecycleStatus::PendingScan,
        MediaLifecycleStatus::Quarantined,
        MediaLifecycleStatus::Failed,
    ]);

    it('generates a variation and creates record', function () {
        $media = createVariationMedia(['disk' => 'public', 'folder' => 'uploads']);

        // Put source file on disk
        Storage::disk('public')->put($media->folder.'/'.$media->hash, 'image content');

        // Mock the processor to return dimensions
        $mock_processor = Mockery::mock(MediaImageTransformer::class);
        $mock_processor->shouldReceive('process')
            ->once()
            ->andReturnUsing(function ($source, $output, $def) {
                file_put_contents($output, 'processed image data');

                return ['width' => 150, 'height' => 150, 'size' => 21];
            });

        app()->instance(MediaImageTransformer::class, $mock_processor);

        $definition = (new ConversionDefinition('thumb'))
            ->width(150)->height(150)->quality(80);

        $action = app(GenerateImageVariationAction::class);
        $result = $action->execute($media, $definition);

        expect($result)->toBeInstanceOf(MediaImageVariation::class)
            ->and($result->exists)->toBeTrue()
            ->and($result->media_id)->toBe($media->id)
            ->and($result->label)->toBe('thumb')
            ->and($result->width)->toBe(150)
            ->and($result->height)->toBe(150)
            ->and($result->format)->toBe('jpg')
            ->and($result->quality)->toBe(80);
    });

    it('stores variation file on parent disk', function () {
        $media = createVariationMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'testhash.jpg']);

        Storage::disk('public')->put('uploads/testhash.jpg', 'original');

        $mock_processor = Mockery::mock(MediaImageTransformer::class);
        $mock_processor->shouldReceive('process')
            ->once()
            ->andReturnUsing(function ($source, $output) {
                file_put_contents($output, 'processed');

                return ['width' => 150, 'height' => 150, 'size' => 9];
            });

        app()->instance(MediaImageTransformer::class, $mock_processor);

        $definition = (new ConversionDefinition('thumb'))
            ->width(150)->height(150);

        $action = app(GenerateImageVariationAction::class);
        $variation = $action->execute($media, $definition);

        expect($variation)->toBeInstanceOf(MediaImageVariation::class);
        Storage::disk('public')->assertExists($variation?->getPath() ?? '');
    });

    it('atomically updates an existing variation without a delete/create gap', function () {
        $media = createVariationMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'regen.jpg']);

        Storage::disk('public')->put('uploads/regen.jpg', 'original');

        // Create an existing variation record
        $existing = MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 100,
            'height' => 100,
            'size' => 200,
            'format' => 'jpg',
            'quality' => 80,
        ]);

        $existing_id = $existing->id;

        // Put the old variation file
        Storage::disk('public')->put('uploads/conversions/regen-thumb.jpg', 'old variation');

        $mock_processor = Mockery::mock(MediaImageTransformer::class);
        $mock_processor->shouldReceive('process')
            ->once()
            ->andReturnUsing(function ($source, $output) {
                file_put_contents($output, 'new variation');

                return ['width' => 150, 'height' => 150, 'size' => 13];
            });

        app()->instance(MediaImageTransformer::class, $mock_processor);

        $definition = (new ConversionDefinition('thumb'))
            ->width(150)->height(150);

        $action = app(GenerateImageVariationAction::class);
        $result = $action->execute($media, $definition);

        expect(MediaImageVariation::find($existing_id))->not->toBeNull()
            ->and($result->id)->toBe($existing_id)
            ->and($result->width)->toBe(150)
            ->and($result->height)->toBe(150)
            ->and($result->storage_path)->not->toBe('uploads/conversions/regen-thumb.jpg');

        Storage::disk('public')->assertExists($result->getPath());
    });

    it('throws ConversionFailedException when processing fails', function () {
        $media = createVariationMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'fail.jpg']);

        Storage::disk('public')->put('uploads/fail.jpg', 'original');

        $mock_processor = Mockery::mock(MediaImageTransformer::class);
        $mock_processor->shouldReceive('process')
            ->once()
            ->andThrow(new RuntimeException('Processing broke'));

        app()->instance(MediaImageTransformer::class, $mock_processor);

        $definition = (new ConversionDefinition('thumb'))
            ->width(150)->height(150);

        $action = app(GenerateImageVariationAction::class);

        $action->execute($media, $definition);
    })->throws(ConversionFailedException::class);

    it('does not create a record when processing fails', function () {
        $media = createVariationMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'norecord.jpg']);

        Storage::disk('public')->put('uploads/norecord.jpg', 'original');

        $mock_processor = Mockery::mock(MediaImageTransformer::class);
        $mock_processor->shouldReceive('process')
            ->once()
            ->andThrow(new RuntimeException('Boom'));

        app()->instance(MediaImageTransformer::class, $mock_processor);

        $definition = (new ConversionDefinition('thumb'))
            ->width(150)->height(150);

        $action = app(GenerateImageVariationAction::class);

        try {
            $action->execute($media, $definition);
        } catch (ConversionFailedException) {
            // Expected
        }

        expect(MediaImageVariation::where('media_id', $media->id)->count())->toBe(0);
    });

    it('uses format from definition when outputFormat is set', function () {
        $media = createVariationMedia(['disk' => 'public', 'folder' => 'uploads', 'hash' => 'fmttest.jpg']);

        Storage::disk('public')->put('uploads/fmttest.jpg', 'original');

        $mock_processor = Mockery::mock(MediaImageTransformer::class);
        $mock_processor->shouldReceive('process')
            ->once()
            ->andReturnUsing(function ($source, $output) {
                file_put_contents($output, 'webp content');

                return ['width' => 320, 'height' => 320, 'size' => 12];
            });

        app()->instance(MediaImageTransformer::class, $mock_processor);

        $definition = (new ConversionDefinition('small'))
            ->width(320)->height(320)->format('webp');

        $action = app(GenerateImageVariationAction::class);
        $result = $action->execute($media, $definition);

        expect($result->format)->toBe('webp')
            ->and($result->label)->toBe('small');

        Storage::disk('public')->assertExists($result->getPath());
    });

    it('materializes a temporary local source file for remote-style disks', function () {
        Storage::fake('s3');
        config(['filesystems.disks.s3.driver' => 's3']);

        $media = createVariationMedia(['disk' => 's3', 'folder' => 'uploads', 'hash' => 'remote-source.jpg']);

        Storage::disk('s3')->put($media->buildPath(), 'original');
        $remoteStoragePath = Storage::disk('s3')->path($media->buildPath());

        $mock_processor = Mockery::mock(MediaImageTransformer::class);
        $mock_processor->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (string $source, string $output) use ($remoteStoragePath): array {
                expect(file_exists($source))->toBeTrue()
                    ->and($source)->not->toBe($remoteStoragePath);

                file_put_contents($output, 'remote processed');

                return ['width' => 150, 'height' => 150, 'size' => 16];
            });

        app()->instance(MediaImageTransformer::class, $mock_processor);

        $definition = (new ConversionDefinition('thumb'))
            ->width(150)->height(150);

        $action = app(GenerateImageVariationAction::class);
        $variation = $action->execute($media, $definition);

        expect($variation)->toBeInstanceOf(MediaImageVariation::class);
        Storage::disk('s3')->assertExists($variation?->getPath() ?? '');
    });
});
