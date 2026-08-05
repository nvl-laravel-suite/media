<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use Nvl\Media\Actions\BulkDeleteMediaAction;
use Nvl\Media\Actions\BulkMoveMediaAction;
use Nvl\Media\Actions\BulkTagMediaAction;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Actions\RenameMediaAction;
use Nvl\Media\Actions\ReplaceMediaFileAction;
use Nvl\Media\Actions\UpdateMediaMetadataAction;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Exceptions\MediaInUseException;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Services\MediaImageTransformer;
use Nvl\Media\Services\MediaQueryService;
use Nvl\Media\Tests\Fixtures\MediaMutationHarness;

function mediaMutationHarness(): MediaMutationHarness
{
    return new MediaMutationHarness(
        app(UpdateMediaMetadataAction::class),
        app(DeleteMediaAction::class),
        app(RenameMediaAction::class),
        app(ReplaceMediaFileAction::class),
        app(BulkDeleteMediaAction::class),
        app(BulkTagMediaAction::class),
        app(BulkMoveMediaAction::class),
    );
}

function createServiceMedia(array $overrides = []): Media
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
        'tags' => ['hero', 'banner'],
        'metadata' => ['source' => 'upload'],
    ], $overrides));
}

function failPublicDiskReads(
    Closure $shouldFail,
    ?bool $throwOnDelete = null,
): void {
    $disk = Storage::disk('public');
    $failingDisk = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig(), $shouldFail, $throwOnDelete) extends FilesystemAdapter
    {
        public function __construct(
            FilesystemOperator $driver,
            FlysystemAdapter $adapter,
            array $config,
            private readonly Closure $shouldFail,
            private readonly ?bool $throwOnDelete,
        ) {
            parent::__construct($driver, $adapter, $config);
        }

        public function readStream($path)
        {
            if (($this->shouldFail)((string) $path)) {
                throw new RuntimeException("Injected read failure for [{$path}].");
            }

            return parent::readStream($path);
        }

        public function delete($paths)
        {
            if ($this->throwOnDelete === true) {
                throw new RuntimeException('Injected staged cleanup failure.');
            }

            if ($this->throwOnDelete === false) {
                return false;
            }

            return parent::delete($paths);
        }
    };

    Storage::set('public', $failingDisk);
}

/* =================================================================
 * index
 * ================================================================= */

describe('index', function () {

    it('returns paginated media', function () {
        createServiceMedia();
        createServiceMedia();
        createServiceMedia();

        $service = app(MediaQueryService::class);
        $filters = MediaFilter::from([]);

        $result = $service->index($filters);

        expect($result->total())->toBe(3)
            ->and($result->items())->toHaveCount(3);
    });

    it('filters by search term', function () {
        createServiceMedia(['filename' => 'sunset-beach.jpg']);
        createServiceMedia(['filename' => 'mountain-view.png', 'extension' => 'png', 'mime_type' => 'image/png']);
        createServiceMedia(['filename' => 'city-lights.jpg']);

        $service = app(MediaQueryService::class);
        $filters = MediaFilter::from(['search' => 'sunset']);

        $result = $service->index($filters);

        expect($result->total())->toBe(1)
            ->and($result->items()[0]->filename)->toBe('sunset-beach.jpg');
    });

    it('delegates search semantics to the configured search driver contract', function () {
        createServiceMedia(['filename' => 'driver-match.jpg']);
        $driver = new class implements MediaSearchDriver
        {
            public bool $applied = false;

            public function apply(Builder $query, string $search): void
            {
                $this->applied = true;
                $query->where('filename', $search);
            }
        };
        app()->instance(MediaSearchDriver::class, $driver);

        $result = app(MediaQueryService::class)->index(
            MediaFilter::from(['search' => 'driver-match.jpg']),
        );

        expect($driver->applied)->toBeTrue()
            ->and($result->total())->toBe(1)
            ->and($result->items()[0]->filename)->toBe('driver-match.jpg');
    });

    it('caps direct service pagination at the configured management limit', function () {
        createServiceMedia();
        config(['media.query.maximum_page_size' => 100]);

        $result = app(MediaQueryService::class)->index(
            new MediaFilter(perPage: 500),
        );

        expect($result->perPage())->toBe(100);
    });

    it('filters by type', function () {
        createServiceMedia(['type' => MediaType::IMAGE]);
        createServiceMedia(['type' => MediaType::DOCUMENT, 'extension' => 'pdf', 'mime_type' => 'application/pdf']);
        createServiceMedia(['type' => MediaType::IMAGE]);

        $service = app(MediaQueryService::class);
        $filters = MediaFilter::from(['type' => 'document']);

        $result = $service->index($filters);

        expect($result->total())->toBe(1)
            ->and($result->items()[0]->type)->toBe(MediaType::DOCUMENT);
    });

    it('filters by disk', function () {
        createServiceMedia(['disk' => 'public']);
        createServiceMedia(['disk' => 's3']);

        $service = app(MediaQueryService::class);
        $filters = MediaFilter::from(['disk' => 's3']);

        $result = $service->index($filters);

        expect($result->total())->toBe(1)
            ->and($result->items()[0]->disk)->toBe('s3');
    });

    it('filters by isPublic', function () {
        createServiceMedia(['is_public' => true]);
        createServiceMedia(['is_public' => false]);
        createServiceMedia(['is_public' => true]);

        $service = app(MediaQueryService::class);
        $filters = MediaFilter::from(['isPublic' => false]);

        $result = $service->index($filters);

        expect($result->total())->toBe(1)
            ->and($result->items()[0]->is_public)->toBeFalse();
    });

    it('filters by tag', function () {
        createServiceMedia(['tags' => ['hero', 'banner']]);
        createServiceMedia(['tags' => ['gallery']]);
        createServiceMedia(['tags' => ['hero', 'featured']]);

        $service = app(MediaQueryService::class);
        $filters = MediaFilter::from(['tag' => 'hero']);

        $result = $service->index($filters);

        expect($result->total())->toBe(2);
    });

    it('sorts by column and direction', function () {
        createServiceMedia(['filename' => 'aaa.jpg', 'size' => 100]);
        createServiceMedia(['filename' => 'zzz.jpg', 'size' => 500]);
        createServiceMedia(['filename' => 'mmm.jpg', 'size' => 300]);

        $service = app(MediaQueryService::class);

        $asc_filters = MediaFilter::from(['sortBy' => 'filename', 'sortDirection' => 'asc']);
        $asc_result = $service->index($asc_filters);

        expect($asc_result->items()[0]->filename)->toBe('aaa.jpg')
            ->and($asc_result->items()[2]->filename)->toBe('zzz.jpg');

        $desc_filters = MediaFilter::from(['sortBy' => 'size', 'sortDirection' => 'desc']);
        $desc_result = $service->index($desc_filters);

        expect($desc_result->items()[0]->size)->toBe(500)
            ->and($desc_result->items()[2]->size)->toBe(100);
    });

    it('loads variations relation only when requested', function () {
        createServiceMedia();

        $service = app(MediaQueryService::class);
        $filters = MediaFilter::from([]);

        $withoutVariations = $service->index($filters, includeVariations: false)->items()[0];
        $withVariations = $service->index($filters, includeVariations: true)->items()[0];

        expect($withoutVariations->relationLoaded('imageVariations'))->toBeFalse()
            ->and($withVariations->relationLoaded('imageVariations'))->toBeTrue();
    });
});

/* =================================================================
 * show
 * ================================================================= */

describe('show', function () {

    it('returns media with relations', function () {
        $media = createServiceMedia();

        MediaTranslation::create([
            'media_id' => $media->id,
            'locale' => 'en',
            'title' => 'Test Title',
        ]);

        $service = app(MediaQueryService::class);
        $result = $service->show($media->id);

        expect($result)->toBeInstanceOf(Media::class)
            ->and($result->id)->toBe($media->id)
            ->and($result->relationLoaded('imageVariations'))->toBeTrue()
            ->and($result->relationLoaded('translations'))->toBeTrue()
            ->and($result->relationLoaded('associations'))->toBeTrue()
            ->and($result->translations)->toHaveCount(1);
    });

    it('throws ModelNotFoundException for invalid id', function () {
        $service = app(MediaQueryService::class);

        $service->show('00000000-0000-0000-0000-000000000000');
    })->throws(ModelNotFoundException::class);

    it('can skip loading variations for detail queries', function () {
        $media = createServiceMedia();

        $service = app(MediaQueryService::class);
        $result = $service->show($media->id, includeVariations: false);

        expect($result->relationLoaded('imageVariations'))->toBeFalse()
            ->and($result->relationLoaded('translations'))->toBeTrue()
            ->and($result->relationLoaded('associations'))->toBeTrue();
    });
});

/* =================================================================
 * update
 * ================================================================= */

describe('update', function () {

    it('updates media metadata fields', function () {
        $media = createServiceMedia(['tags' => ['old'], 'is_public' => false]);

        $service = mediaMutationHarness();

        $updated = $service->update($media->id, [
            'tags' => ['new', 'updated'],
            'is_public' => true,
        ]);

        expect($updated->tags)->toBe(['new', 'updated'])
            ->and($updated->is_public)->toBeTrue();
    });

    it('preserves omitted metadata and supports explicit null clears', function () {
        $media = createServiceMedia([
            'tags' => ['keep-unless-cleared'],
            'metadata' => ['source' => 'import'],
            'is_public' => true,
        ]);

        $service = mediaMutationHarness();
        $preserved = $service->update($media->id, ['is_public' => false]);

        expect($preserved->tags)->toBe(['keep-unless-cleared'])
            ->and($preserved->metadata)->toBe(['source' => 'import']);

        $cleared = $service->update($media->id, [
            'tags' => null,
            'metadata' => null,
        ]);

        expect($cleared->tags)->toBeNull()
            ->and($cleared->metadata)->toBeNull();
    });

    it('creates or updates translations', function () {
        $media = createServiceMedia();

        $service = mediaMutationHarness();

        // Create translation
        $updated = $service->update($media->id, [
            'translations' => [
                'en' => [
                    'title' => 'My Title',
                    'alt' => 'My Alt',
                    'caption' => 'My Caption',
                ],
            ],
        ]);

        expect($updated->translations)->toHaveCount(1);

        $translation = $updated->translations->first();

        expect($translation->locale)->toBe('en')
            ->and($translation->title)->toBe('My Title')
            ->and($translation->alt)->toBe('My Alt')
            ->and($translation->caption)->toBe('My Caption');

        // Update same locale translation
        $updated2 = $service->update($media->id, [
            'translations' => [
                'en' => ['title' => 'Updated Title'],
            ],
        ]);

        expect($updated2->translations)->toHaveCount(1)
            ->and($updated2->translations->first()->title)->toBe('Updated Title');
    });

    it('clears an explicitly null localized field without clearing omitted fields', function () {
        $media = createServiceMedia();
        $service = mediaMutationHarness();

        $service->update($media->id, [
            'translations' => [
                'en' => [
                    'title' => 'Title',
                    'alt' => 'Alternative',
                ],
            ],
        ]);

        $updated = $service->update($media->id, [
            'translations' => [
                'en' => ['alt' => null],
            ],
        ]);

        expect($updated->translated('title', 'en'))->toBe('Title')
            ->and($updated->translations->first()->alt)->toBeNull();
    });

    it('uses deterministic field-level fallback across media read paths', function () {
        $media = createServiceMedia();
        $service = mediaMutationHarness();

        $service->update($media->id, [
            'translations' => [
                'en' => [
                    'title' => 'English title',
                    'alt' => 'English alt',
                ],
            ],
        ]);
        $updated = $service->update($media->id, [
            'translations' => [
                'bg' => ['title' => 'Българско заглавие'],
            ],
        ]);

        expect($updated->translated('title', 'bg'))->toBe('Българско заглавие')
            ->and($updated->translated('alt', 'bg'))->toBe('English alt')
            ->and($updated->resolveTranslation('alt', 'bg')->usedFallback())->toBeTrue();
    });

    it('supports locale-keyed patch and replace translation mutations', function () {
        config(['translatable.locales' => ['en', 'bg', 'fr']]);

        $media = createServiceMedia();
        $service = mediaMutationHarness();

        $updated = $service->update($media->id, [
            'translations' => [
                'en' => ['title' => 'English title', 'alt' => 'English alt'],
                'bg' => ['title' => 'Българско заглавие'],
                'fr' => ['title' => 'Titre français'],
            ],
        ]);

        expect($updated->getAvailableLocales())
            ->toEqualCanonicalizing(['en', 'bg', 'fr']);

        $patched = $service->update($media->id, [
            'translations' => [
                'bg' => ['caption' => 'Български надпис'],
            ],
        ]);

        expect($patched->getAvailableLocales())
            ->toEqualCanonicalizing(['en', 'bg', 'fr'])
            ->and($patched->translated('title', 'bg'))->toBe('Българско заглавие')
            ->and($patched->translated('caption', 'bg'))->toBe('Български надпис');

        $replaced = $service->update($media->id, [
            'translations' => [
                'en' => ['title' => 'Replacement title'],
                'bg' => ['title' => 'Заместено заглавие'],
            ],
            'translationMode' => 'replace',
        ]);

        expect($replaced->getAvailableLocales())
            ->toEqualCanonicalizing(['en', 'bg'])
            ->and($replaced->hasTranslation('fr'))->toBeFalse();
    });

    it('validates array payloads before applying any metadata or translation writes', function () {
        $media = createServiceMedia(['tags' => ['original']]);

        expect(fn () => mediaMutationHarness()->update($media->id, [
            'tags' => ['changed'],
            'translations' => [
                'en' => ['unknown' => 'not allowed'],
            ],
        ]))->toThrow(ValidationException::class);

        expect($media->fresh()->tags)->toBe(['original'])
            ->and($media->fresh()->translations)->toHaveCount(0);
    });

    it('prevents a reused public asset from becoming private', function () {
        $media = createServiceMedia(['is_public' => true]);

        foreach (['owner-a', 'owner-b'] as $ownerId) {
            MediaAssociation::create([
                'media_id' => $media->id,
                'associable_type' => 'test-owner',
                'associable_id' => $ownerId,
                'collection' => 'gallery',
                'order' => 0,
            ]);
        }

        expect(fn () => mediaMutationHarness()->update($media->id, [
            'is_public' => false,
        ]))->toThrow(MediaInUseException::class);

        expect($media->fresh()->is_public)->toBeTrue();
    });

    it('wraps in transaction', function () {
        $media = createServiceMedia(['tags' => ['original']]);

        $service = mediaMutationHarness();

        // A successful update
        $updated = $service->update($media->id, [
            'tags' => ['changed'],
        ]);

        expect($updated->tags)->toBe(['changed']);

        // Verify it persisted
        $fresh = Media::find($media->id);
        expect($fresh->tags)->toBe(['changed']);
    });
});

/* =================================================================
 * delete
 * ================================================================= */

describe('delete', function () {

    it('delegates to DeleteMediaAction', function () {
        $media = createServiceMedia();
        $media_id = $media->id;

        $service = mediaMutationHarness();
        $result = $service->delete($media_id);

        expect($result)->toBeTrue();
        expect(Media::query()->find($media_id))->toBeNull()
            ->and(Media::withTrashed()->find($media_id)?->trashed())->toBeTrue();
    });
});

/* =================================================================
 * replace
 * ================================================================= */

describe('replace', function () {

    beforeEach(function () {
        Storage::fake('public');
    });

    it('replaces the stored file and updates media metadata', function () {
        $media = createServiceMedia([
            'filename' => 'old.jpg',
            'hash' => 'old-hash.jpg',
            'folder' => 'test',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'type' => MediaType::IMAGE,
        ]);

        Storage::disk('public')->put($media->buildPath(), 'old-content');

        $service = mediaMutationHarness();
        $replacement = UploadedFile::fake()->createWithContent('replacement.txt', 'replacement-content');

        $updated = $service->replace($media->id, $replacement);

        expect($updated->filename)->toBe('replacement.txt')
            ->and($updated->hash)->not->toBe('old-hash.jpg')
            ->and($updated->extension)->toBe('txt')
            ->and($updated->mime_type)->toBe('text/plain');

        Storage::disk('public')->assertMissing('media/test/old-hash.jpg');
        Storage::disk('public')->assertExists($updated->buildPath());
    });

    it('deletes the newly stored replacement file when the database transaction fails', function () {
        $media = createServiceMedia([
            'filename' => 'old.jpg',
            'hash' => 'old-hash.jpg',
            'folder' => 'test',
        ]);

        Storage::disk('public')->put($media->buildPath(), 'old-content');

        $connection = DB::connection();
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('replace transaction failed'));

        $service = mediaMutationHarness();
        $replacement = UploadedFile::fake()->createWithContent('replacement.txt', 'replacement-content');

        expect(fn () => $service->replace($media->id, $replacement))
            ->toThrow(RuntimeException::class, 'replace transaction failed');

        $media->refresh();

        expect($media->filename)->toBe('old.jpg')
            ->and(Storage::disk('public')->files('media/test'))->toBe(['media/test/old-hash.jpg']);
    });

    it('deletes a staged replacement when post-write integrity verification cannot read it', function () {
        $media = createServiceMedia([
            'filename' => 'old.txt',
            'hash' => 'old-hash.txt',
            'folder' => 'test',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'type' => MediaType::DOCUMENT,
            'digest' => hash('sha256', 'old-content'),
            'size' => 11,
        ]);
        Storage::disk('public')->put($media->buildPath(), 'old-content');
        failPublicDiskReads(
            static fn (string $path): bool => $path !== 'media/test/old-hash.txt',
        );

        expect(fn () => mediaMutationHarness()->replace(
            $media->id,
            UploadedFile::fake()->createWithContent('replacement.txt', 'replacement-content'),
        ))->toThrow(RuntimeException::class, 'Unable to read');

        expect($media->fresh()?->hash)->toBe('old-hash.txt')
            ->and(Storage::disk('public')->files('media/test'))->toBe(['media/test/old-hash.txt']);
    });

    it('preserves the replacement verification error when staged cleanup cannot complete', function (bool $throwOnDelete) {
        $media = createServiceMedia([
            'filename' => 'old.txt',
            'hash' => 'old-cleanup-failure.txt',
            'folder' => 'test',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'type' => MediaType::DOCUMENT,
            'digest' => hash('sha256', 'old-content'),
            'size' => 11,
        ]);
        Storage::disk('public')->put($media->buildPath(), 'old-content');
        failPublicDiskReads(
            static fn (string $path): bool => $path !== 'media/test/old-cleanup-failure.txt',
            $throwOnDelete,
        );

        expect(fn () => mediaMutationHarness()->replace(
            $media->id,
            UploadedFile::fake()->createWithContent('replacement.txt', 'replacement-content'),
        ))->toThrow(RuntimeException::class, 'Unable to read');

        expect($media->fresh()?->hash)->toBe('old-cleanup-failure.txt')
            ->and(Storage::disk('public')->get($media->buildPath()))->toBe('old-content')
            ->and(Storage::disk('public')->files('media/test'))->toHaveCount(2);
    })->with([
        'cleanup returns false' => false,
        'cleanup throws' => true,
    ]);

    it('rejects malicious SVG replacements without mutating the existing media', function () {
        $media = createServiceMedia([
            'filename' => 'old.jpg',
            'hash' => 'old-hash.jpg',
            'folder' => 'test',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'type' => MediaType::IMAGE,
            'digest' => 'old-digest',
        ]);

        Storage::disk('public')->put($media->buildPath(), 'old-content');

        $service = mediaMutationHarness();
        $replacement = UploadedFile::fake()->createWithContent(
            'malicious.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("x")</script></svg>',
        );

        expect(fn () => $service->replace($media->id, $replacement))
            ->toThrow(MediaUploadException::class);

        $media->refresh();

        expect($media->filename)->toBe('old.jpg')
            ->and($media->hash)->toBe('old-hash.jpg')
            ->and($media->extension)->toBe('jpg')
            ->and($media->digest)->toBe('old-digest');

        Storage::disk('public')->assertExists('media/test/old-hash.jpg');
        expect(Storage::disk('public')->files('media/test'))->toBe(['media/test/old-hash.jpg']);
    });

    it('applies the configured malware scanner to replacements', function () {
        $media = createServiceMedia([
            'filename' => 'old.txt',
            'hash' => 'old-hash.txt',
            'folder' => 'test',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'type' => MediaType::DOCUMENT,
            'digest' => hash('sha256', 'old-content'),
            'size' => 11,
        ]);
        Storage::disk('public')->put($media->buildPath(), 'old-content');
        app()->instance(MediaContentScanner::class, new class implements MediaContentScanner
        {
            public function scan(UploadedFile $file): void
            {
                throw new MediaUploadException('Replacement malware detected.');
            }
        });

        expect(fn () => mediaMutationHarness()->replace(
            $media->id,
            UploadedFile::fake()->createWithContent('replacement.txt', 'replacement'),
        ))->toThrow(MediaUploadException::class, 'Replacement malware detected.');

        expect($media->fresh()?->hash)->toBe('old-hash.txt');
        Storage::disk('public')->assertExists($media->buildPath());
    });

    it('accepts safe SVG replacements and updates media metadata', function () {
        $media = createServiceMedia([
            'filename' => 'old.jpg',
            'hash' => 'old-hash.jpg',
            'folder' => 'test',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'type' => MediaType::IMAGE,
        ]);

        Storage::disk('public')->put($media->buildPath(), 'old-content');

        $service = mediaMutationHarness();
        $replacement = UploadedFile::fake()->createWithContent(
            'safe.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" /></svg>',
        );

        $updated = $service->replace($media->id, $replacement);

        expect($updated->filename)->toBe('safe.svg')
            ->and($updated->extension)->toBe('svg')
            ->and($updated->type)->toBe(MediaType::IMAGE);

        Storage::disk('public')->assertMissing('media/test/old-hash.jpg');
        Storage::disk('public')->assertExists($updated->buildPath());
    });

    it('preserves variation identity and schedules regeneration after replacing an image', function () {
        config([
            'media.auto_generate_variations' => true,
            'media.queue.enabled' => false,
            'media.image_variation_presets' => [
                'thumb' => [
                    'width' => 150,
                    'height' => 150,
                    'quality' => 80,
                    'format' => 'jpg',
                    'enabled' => true,
                ],
            ],
            'media.output_conversion' => [
                'enabled' => false,
            ],
        ]);

        $media = createServiceMedia([
            'filename' => 'old.jpg',
            'hash' => 'old-hash.jpg',
            'folder' => 'test',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'type' => MediaType::IMAGE,
        ]);

        Storage::disk('public')->put($media->buildPath(), 'old-content');
        Storage::disk('public')->put('media/test/conversions/old-hash-thumb.jpg', 'old-thumb');

        MediaImageVariation::create([
            'media_id' => $media->id,
            'label' => 'thumb',
            'width' => 150,
            'height' => 150,
            'size' => 8,
            'format' => 'jpg',
            'quality' => 80,
        ]);

        $mockTransformer = Mockery::mock(MediaImageTransformer::class);
        $mockTransformer->shouldReceive('process')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($source, $output) {
                file_put_contents($output, 'rebuilt-thumb');

                return ['width' => 150, 'height' => 150, 'size' => 13];
            });

        app()->instance(MediaImageTransformer::class, $mockTransformer);

        $service = mediaMutationHarness();
        $replacement = UploadedFile::fake()->image('replacement.jpg', 300, 300);

        $updated = $service->replace($media->id, $replacement);
        $variation = $updated->imageVariations->firstWhere('label', 'thumb');

        expect($variation)->toBeInstanceOf(MediaImageVariation::class)
            ->and($updated->imageVariations)->toHaveCount(1)
            ->and($variation?->format)->toBe('jpg')
            ->and($variation?->source_revision)->toBeIn([1, $updated->revision]);

        Storage::disk('public')->assertExists($updated->buildPath());

        if ($variation?->source_revision === $updated->revision) {
            Storage::disk('public')->assertExists($variation->getPath());
        }
    });
});

/* =================================================================
 * bulkMove
 * ================================================================= */

describe('bulkMove', function () {

    beforeEach(function () {
        Storage::fake('public');
    });

    it('moves stored files and updates the media folder', function () {
        $media = createServiceMedia([
            'hash' => 'move-hash.jpg',
            'folder' => 'old-folder',
        ]);

        Storage::disk('public')->put($media->buildPath(), 'move-content');

        $service = mediaMutationHarness();
        $moved = $service->bulkMove([$media->id], 'new-folder');

        expect($moved)->toBe(1);

        $media->refresh();

        expect($media->folder)->toBe('new-folder');
        Storage::disk('public')->assertMissing('media/old-folder/move-hash.jpg');
        Storage::disk('public')->assertExists('media/new-folder/move-hash.jpg');
    });

    it('rolls moved files back when the database transaction fails after the filesystem move', function () {
        $media = createServiceMedia([
            'hash' => 'move-hash.jpg',
            'folder' => 'old-folder',
        ]);

        Storage::disk('public')->put($media->buildPath(), 'move-content');

        $connection = DB::connection();
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('bulk move transaction failed'));

        $service = mediaMutationHarness();

        expect(fn () => $service->bulkMove([$media->id], 'new-folder'))
            ->toThrow(RuntimeException::class, 'bulk move transaction failed');

        $media->refresh();

        expect($media->folder)->toBe('old-folder');
        Storage::disk('public')->assertExists('media/old-folder/move-hash.jpg');
        Storage::disk('public')->assertMissing('media/new-folder/move-hash.jpg');
    });

    it('deletes the copied destination when post-copy integrity verification fails', function () {
        $media = createServiceMedia([
            'hash' => 'move-hash.jpg',
            'folder' => 'old-folder',
        ]);
        Storage::disk('public')->put($media->buildPath(), 'move-content');
        failPublicDiskReads(
            static fn (string $path): bool => str_starts_with($path, 'media/new-folder/'),
        );

        expect(fn () => mediaMutationHarness()->bulkMove([$media->id], 'new-folder'))
            ->toThrow(RuntimeException::class, 'Unable to read');

        expect($media->fresh()?->folder)->toBe('old-folder');
        Storage::disk('public')->assertExists('media/old-folder/move-hash.jpg');
        Storage::disk('public')->assertMissing('media/new-folder/move-hash.jpg');
    });
});
