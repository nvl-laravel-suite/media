<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaNotReusableException;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\MediaAdder;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaImageTransformer;
use Nvl\Media\Services\MediaModelInteractionService;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaSourceResolver;
use Nvl\Media\Services\MediaTemporaryFileRegistry;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Media\Tests\Stubs\TestMediaUser;

beforeEach(function () {
    Storage::fake('public');

    if (! Schema::hasTable('test_media_models')) {
        Schema::create('test_media_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    config([
        'filesystems.default' => 'public',
        'media.auto_generate_variations' => false,
        'media.auto_generate_conversions' => false,
        'media.output_conversion.enabled' => false,
    ]);
});

function adderTestModel(array $overrides = []): TestMediaModel
{
    return TestMediaModel::create(array_merge([
        'name' => 'Adder Test Model',
    ], $overrides));
}

function adderOwnerCollectionLockKey(TestMediaModel $model, string $collection): string
{
    $scope = hash('sha256', implode("\0", [
        $model->getMorphClass(),
        (string) $model->getKey(),
        $collection,
    ]));

    return 'media:mutation:'.hash('sha256', "owner-collection:{$scope}");
}

function adderMutationLockKey(string $identity): string
{
    return 'media:mutation:'.hash('sha256', $identity);
}

function canAcquireAdderMutationLock(string $identity): bool
{
    $probe = Cache::store('array')->lock(adderMutationLockKey($identity), 10);
    $acquired = $probe->get();

    if ($acquired) {
        $probe->release();
    }

    return $acquired;
}

/* =================================================================
 * Fluent builder chaining
 * ================================================================= */

describe('fluent builder', function () {

    it('all chainable methods return the same instance', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg');
        $adder = app(MediaModelInteractionService::class)->newAdder($model, $file);

        $result = $adder
            ->preservingOriginal()
            ->usingFileName('custom.jpg')
            ->sanitizingFileName(fn ($name) => $name)
            ->withCustomProperties(['key' => 'value'])
            ->withTags(['hero'])
            ->toLocale('en')
            ->withOrder(5)
            ->toDisk('public')
            ->toFolder('custom/path')
            ->asPublic()
            ->withVariations([])
            ->withoutVariations()
            ->withAssociationMeta(['note' => 'test']);

        expect($result)->toBeInstanceOf(MediaAdder::class);
    });
});

/* =================================================================
 * slot()
 * ================================================================= */

describe('slot', function () {

    it('uploads file and creates media record', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->withoutVariations()
            ->slot('gallery');

        expect($media)->toBeInstanceOf(Media::class)
            ->and($media->exists)->toBeTrue()
            ->and($media->extension)->toBe('jpg')
            ->and($media->mime_type)->toBe('image/jpeg')
            ->and($media->disk)->toBe('public');
    });

    it('creates association pivot record', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->withoutVariations()
            ->slot('avatar');

        $assoc = MediaAssociation::where('media_id', $media->id)
            ->where('associable_id', $model->id)
            ->first();

        expect($assoc)->not->toBeNull()
            ->and($assoc->collection)->toBe('avatar');
    });

    it('applies custom filename', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->usingFileName('custom-name.jpg')
            ->withoutVariations()
            ->slot('gallery');

        expect($media->filename)->toBe('custom-name.jpg');
    });

    it('applies custom sanitizer', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('My Photo (1).jpg', 100, 100);

        $media = $model->addMedia($file)
            ->sanitizingFileName(fn ($name) => strtolower(str_replace(' ', '-', $name)))
            ->withoutVariations()
            ->slot('gallery');

        expect($media->filename)->toBe('my-photo-(1).jpg');
    });

    it('applies default sanitization', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('My Photo!.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->withoutVariations()
            ->slot('gallery');

        // Default sanitizer replaces spaces with hyphens and removes special chars
        expect($media->filename)->not->toContain('!')
            ->and($media->filename)->not->toContain(' ');
    });

    it('stores tags from builder and collection defaults', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $collection = $model->addMediaSlot('gallery')
            ->withTags(['default-tag']);

        $media = $model->addMedia($file)
            ->withTags(['custom-tag'])
            ->withoutVariations()
            ->slot('gallery');

        expect($media->tags)->toContain('default-tag')
            ->and($media->tags)->toContain('custom-tag');
    });

    it('applies custom properties as metadata', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->withCustomProperties(['source' => 'api', 'version' => 2])
            ->withoutVariations()
            ->slot('gallery');

        expect($media->metadata)->toBe(['source' => 'api', 'version' => 2]);
    });

    it('applies disk override', function () {
        Storage::fake('s3');
        config(['media.allowed_disks' => ['public', 's3']]);

        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->toDisk('s3')
            ->withoutVariations()
            ->slot('gallery');

        expect($media->disk)->toBe('s3');
    });

    it('applies folder override', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->toFolder('custom/folder')
            ->withoutVariations()
            ->slot('gallery');

        expect($media->folder)->toBe('custom/folder');
    });

    it('applies public override', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->asPublic()
            ->withoutVariations()
            ->slot('gallery');

        expect($media->is_public)->toBeTrue();
    });

    it('attributes queued uploads to an explicit uploader without an authenticated request', function () {
        $model = adderTestModel();
        $uploader = TestMediaUser::withoutEvents(
            static fn (): TestMediaUser => TestMediaUser::forceCreate([
                'name' => 'Queued uploader',
                'email' => 'queued-uploader@example.test',
                'password' => 'secret',
            ]),
        );

        $media = $model->addMedia(UploadedFile::fake()->createWithContent('queued.txt', 'queued'))
            ->uploadedBy($uploader)
            ->withoutVariations()
            ->slot('documents');

        expect(auth()->user())->toBeNull()
            ->and($media->uploaded_by)->toBe((string) $uploader->getAuthIdentifier())
            ->and($media->uploaded_by_type)->toBe($uploader->getMorphClass());
    });

    it('reuses identical media rows for shared slots across owners', function () {
        $firstModel = adderTestModel(['name' => 'First Shared Owner']);
        $secondModel = adderTestModel(['name' => 'Second Shared Owner']);

        $firstModel->addMediaSlot('shared-gallery')->publicReusable();
        $secondModel->addMediaSlot('shared-gallery')->publicReusable();

        $firstMedia = $firstModel->addMedia(UploadedFile::fake()->createWithContent('shared.txt', 'shared-media'))
            ->withoutVariations()
            ->slot('shared-gallery');

        $secondMedia = $secondModel->addMedia(UploadedFile::fake()->createWithContent('shared.txt', 'shared-media'))
            ->withoutVariations()
            ->slot('shared-gallery');

        expect($firstMedia->id)->toBe($secondMedia->id);
        expect(MediaAssociation::query()->where('media_id', $firstMedia->id)->count())->toBe(2);
    });

    it('creates distinct media rows for exclusive slots across owners', function () {
        $firstModel = adderTestModel(['name' => 'First Exclusive Owner']);
        $secondModel = adderTestModel(['name' => 'Second Exclusive Owner']);

        $firstModel->addMediaSlot('exclusive-avatar')->exclusive();
        $secondModel->addMediaSlot('exclusive-avatar')->exclusive();

        $firstMedia = $firstModel->addMedia(UploadedFile::fake()->createWithContent('avatar.txt', 'exclusive-media'))
            ->withoutVariations()
            ->slot('exclusive-avatar');

        $secondMedia = $secondModel->addMedia(UploadedFile::fake()->createWithContent('avatar.txt', 'exclusive-media'))
            ->withoutVariations()
            ->slot('exclusive-avatar');

        expect($firstMedia->id)->not->toBe($secondMedia->id);
        expect(MediaAssociation::query()->where('media_id', $firstMedia->id)->count())->toBe(1);
        expect(MediaAssociation::query()->where('media_id', $secondMedia->id)->count())->toBe(1);
    });

    it('does not deduplicate anonymous private uploads', function () {
        $firstModel = adderTestModel(['name' => 'First Private Owner']);
        $secondModel = adderTestModel(['name' => 'Second Private Owner']);

        $first = $firstModel->addMedia(UploadedFile::fake()->createWithContent('private.txt', 'private-content'))
            ->withoutVariations()
            ->slot('private-files');

        $second = $secondModel->addMedia(UploadedFile::fake()->createWithContent('private.txt', 'private-content'))
            ->withoutVariations()
            ->slot('private-files');

        expect($first->id)->not->toBe($second->id);
    });

    it('reuses an existing public asset without copying the stored media row', function () {
        $source = adderTestModel(['name' => 'Public Asset Source']);
        $consumer = adderTestModel(['name' => 'Public Asset Consumer']);

        $source->addMediaSlot('library')->publicReusable();

        $asset = $source->addMedia(UploadedFile::fake()->createWithContent('logo.svg', '<svg></svg>'))
            ->withoutVariations()
            ->slot('library');

        $reused = $consumer->reusePublicMedia(
            media: $asset,
            collection: 'brand-assets',
            metadata: ['role' => 'logo'],
            dispatchVariations: false,
        );

        expect($reused->is($asset))->toBeTrue()
            ->and(Media::query()->count())->toBe(1)
            ->and(MediaAssociation::query()->where('media_id', $asset->id)->count())->toBe(2)
            ->and($consumer->getFirstMedia('brand-assets')?->is($asset))->toBeTrue();
    });

    it('rejects attempts to reuse private media as a public asset', function () {
        $source = adderTestModel(['name' => 'Private Asset Source']);
        $consumer = adderTestModel(['name' => 'Private Asset Consumer']);

        $privateMedia = $source->addMedia(UploadedFile::fake()->createWithContent('secret.txt', 'secret'))
            ->withoutVariations()
            ->slot('documents');

        expect(fn () => $consumer->reusePublicMedia($privateMedia))
            ->toThrow(MediaNotReusableException::class);
    });

    it('applies locale and order to association', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->toLocale('en')
            ->withOrder(5)
            ->withoutVariations()
            ->slot('gallery');

        $assoc = MediaAssociation::where('media_id', $media->id)->first();

        expect($assoc->locale)->toBe('en')
            ->and($assoc->order)->toBe(5);
    });

    it('applies association metadata to pivot', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->withAssociationMeta(['note' => 'test note'])
            ->withoutVariations()
            ->slot('gallery');

        $assoc = MediaAssociation::where('media_id', $media->id)->first();

        expect($assoc->metadata)->toBe([
            'note' => 'test note',
            'slot' => 'gallery',
        ]);
    });

    it('persists the originating slot when collection is overridden', function () {
        $model = adderTestModel();
        $model->addMediaSlot('avatar')
            ->addConversion('thumb', fn ($definition) => $definition->width(100)->height(100));

        $mockTransformer = Mockery::mock(MediaImageTransformer::class);
        $mockTransformer->shouldReceive('process')
            ->once()
            ->andReturnUsing(function ($source, $output) {
                file_put_contents($output, 'thumb');

                return ['width' => 100, 'height' => 100, 'size' => 5];
            });

        app()->instance(MediaImageTransformer::class, $mockTransformer);

        $media = $model->addMedia(UploadedFile::fake()->image('photo.jpg', 100, 100))
            ->toCollection('profile-gallery')
            ->slot('avatar');

        $assoc = MediaAssociation::where('media_id', $media->id)->first();

        expect($assoc)->not->toBeNull()
            ->and($assoc->collection)->toBe('profile-gallery')
            ->and(data_get($assoc->metadata, 'slot'))->toBe('avatar');
    });

    it('dispatches slot conversions only once during upload', function () {
        $model = adderTestModel();
        $model->addMediaSlot('avatar')
            ->addConversion('thumb', fn ($definition) => $definition->width(100)->height(100));

        $mockTransformer = Mockery::mock(MediaImageTransformer::class);
        $mockTransformer->shouldReceive('process')
            ->once()
            ->andReturnUsing(function ($source, $output) {
                file_put_contents($output, 'thumb');

                return ['width' => 100, 'height' => 100, 'size' => 5];
            });

        app()->instance(MediaImageTransformer::class, $mockTransformer);

        $model->addMedia(UploadedFile::fake()->image('photo.jpg', 100, 100))
            ->slot('avatar');
    });

    it('does not dispatch slot conversions when variations are disabled', function () {
        $model = adderTestModel();
        $model->addMediaSlot('avatar')
            ->addConversion('thumb', fn ($definition) => $definition->width(100)->height(100));

        $mockTransformer = Mockery::mock(MediaImageTransformer::class);
        $mockTransformer->shouldNotReceive('process');

        app()->instance(MediaImageTransformer::class, $mockTransformer);

        $model->addMedia(UploadedFile::fake()->image('photo.jpg', 100, 100))
            ->withoutVariations()
            ->slot('avatar');
    });

    it('persists and generates normalized upload-specific named variations', function () {
        $model = adderTestModel();
        $mockTransformer = Mockery::mock(MediaImageTransformer::class);
        $mockTransformer->shouldReceive('process')
            ->once()
            ->andReturnUsing(function ($source, $output) {
                file_put_contents($output, 'card');

                return ['width' => 320, 'height' => 180, 'size' => 4];
            });
        app()->instance(MediaImageTransformer::class, $mockTransformer);

        $media = $model->addMedia(UploadedFile::fake()->image('photo.jpg', 640, 360))
            ->withVariations([
                'card' => ['width' => 320, 'height' => 180, 'format' => 'jpg'],
            ])
            ->slot('gallery')
            ->refresh();

        expect($media->variation_definitions)->toHaveKey('card')
            ->and($media->imageVariations()->where('label', 'card')->exists())->toBeTrue();
    });

    it('persists full conversion definitions while suppressing automatic dispatch', function () {
        $model = adderTestModel();
        $definition = (new ConversionDefinition('card'))
            ->fit('max', 640, 480)
            ->format('webp')
            ->sharpen(5);
        $mockTransformer = Mockery::mock(MediaImageTransformer::class);
        $mockTransformer->shouldNotReceive('process');
        app()->instance(MediaImageTransformer::class, $mockTransformer);

        $media = $model->addMedia(UploadedFile::fake()->image('photo.jpg', 640, 360))
            ->withVariations(['card' => $definition])
            ->withoutVariations()
            ->slot('gallery');
        $payload = $media->variation_definitions['card'] ?? [];

        expect($payload['fit_method'] ?? null)->toBe('max')
            ->and($payload['output_format'] ?? null)->toBe('webp')
            ->and($payload['sharpen_amount'] ?? null)->toBe(5);
    });

    it('rejects conflicting definitions when a shared upload deduplicates', function () {
        $first = adderTestModel(['name' => 'First variation owner']);
        $second = adderTestModel(['name' => 'Second variation owner']);
        $first->addMediaSlot('library')->publicReusable();
        $second->addMediaSlot('library')->publicReusable();

        $first->addMedia(UploadedFile::fake()->createWithContent('shared.txt', 'same'))
            ->withVariations(['preview' => ['width' => 100]])
            ->withoutVariations()
            ->slot('library');

        expect(fn () => $second
            ->addMedia(UploadedFile::fake()->createWithContent('shared.txt', 'same'))
            ->withVariations(['preview' => ['width' => 200]])
            ->withoutVariations()
            ->slot('library'))
            ->toThrow(MediaUploadException::class, 'conflicting variation definition');
    });
});

/* =================================================================
 * upload() shortcut
 * ================================================================= */

describe('upload shortcut', function () {

    it('uploads to default collection', function () {
        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->withoutVariations()
            ->upload();

        $assoc = MediaAssociation::where('media_id', $media->id)->first();

        expect($assoc->collection)->toBe('default');
    });
});

describe('temporary source ownership', function () {
    it('releases package-created temporary files after a terminal upload', function () {
        $model = adderTestModel();
        $resolver = app(MediaSourceResolver::class);
        $registry = app(MediaTemporaryFileRegistry::class);
        $file = $resolver->fromString('temporary content');
        $path = $file->getRealPath();

        expect($registry->count())->toBe(1);

        app(MediaModelInteractionService::class)
            ->newAdder($model, $file)
            ->withoutVariations()
            ->slot('documents');

        expect($registry->count())->toBe(0)
            ->and(is_string($path) && file_exists($path))->toBeFalse();
    });

    it('releases package-created temporary files after upload rejection', function () {
        $model = adderTestModel();
        $model->addMediaSlot('documents')->maxFileSize(1);
        $resolver = app(MediaSourceResolver::class);
        $registry = app(MediaTemporaryFileRegistry::class);
        $file = $resolver->fromString('too large');
        $path = $file->getRealPath();

        expect(fn () => app(MediaModelInteractionService::class)
            ->newAdder($model, $file)
            ->withoutVariations()
            ->slot('documents'))
            ->toThrow(FileUnacceptableForCollection::class);

        expect($registry->count())->toBe(0)
            ->and(is_string($path) && file_exists($path))->toBeFalse();
    });

    it('retains explicit local sources when preservingOriginal is selected', function () {
        $model = adderTestModel();
        $source = tempnam(sys_get_temp_dir(), 'media_owner_');
        expect($source)->toBeString();
        file_put_contents($source, 'preserved');

        try {
            $model->copyMedia($source)
                ->withoutVariations()
                ->slot('documents');

            expect(file_exists($source))->toBeTrue();
        } finally {
            if (is_string($source) && is_file($source)) {
                unlink($source);
            }
        }
    });

    it('deletes owned explicit local sources only after the real commit', function () {
        $model = adderTestModel();
        $source = tempnam(sys_get_temp_dir(), 'media_owner_');
        expect($source)->toBeString();
        file_put_contents($source, 'owned');

        try {
            DB::beginTransaction();
            $model->addMedia($source)
                ->withoutVariations()
                ->slot('documents');

            expect(file_exists($source))->toBeTrue();
            DB::commit();
            expect(file_exists($source))->toBeFalse();
        } finally {
            if (DB::transactionLevel() > 1) {
                DB::rollBack();
            }

            if (is_string($source) && is_file($source)) {
                unlink($source);
            }
        }
    });

    it('retains owned explicit local sources when an outer transaction rolls back', function () {
        DB::beginTransaction();
        $model = adderTestModel();
        $source = tempnam(sys_get_temp_dir(), 'media_owner_');
        expect($source)->toBeString();
        file_put_contents($source, 'rollback');

        try {
            $model->addMedia($source)
                ->withoutVariations()
                ->slot('documents');

            expect(file_exists($source))->toBeTrue();
            DB::rollBack();
            expect(file_exists($source))->toBeTrue();
        } finally {
            if (DB::transactionLevel() > 1) {
                DB::rollBack();
            }

            if (is_string($source) && is_file($source)) {
                unlink($source);
            }
        }
    });
});

/* =================================================================
 * Validation
 * ================================================================= */

describe('validation', function () {

    it('rejects file with unaccepted MIME type', function () {
        $model = adderTestModel();
        $model->addMediaSlot('images')
            ->acceptsMimeTypes(['image/png']);

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $model->addMedia($file)
            ->withoutVariations()
            ->slot('images');
    })->throws(FileUnacceptableForCollection::class);

    it('accepts file with matching MIME type', function () {
        $model = adderTestModel();
        $model->addMediaSlot('images')
            ->acceptsMimeTypes(['image/jpeg', 'image/png']);

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->withoutVariations()
            ->slot('images');

        expect($media)->toBeInstanceOf(Media::class);
    });

    it('rejects file exceeding max size', function () {
        $model = adderTestModel();
        $model->addMediaSlot('small')
            ->maxFileSize(100); // 100 bytes

        $file = UploadedFile::fake()->image('large.jpg', 500, 500);

        $model->addMedia($file)
            ->withoutVariations()
            ->slot('small');
    })->throws(FileUnacceptableForCollection::class);

    it('rejects file via custom acceptor', function () {
        $model = adderTestModel();
        $model->addMediaSlot('restricted')
            ->acceptsFile(fn (UploadedFile $file) => $file->getClientOriginalExtension() === 'png');

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $model->addMedia($file)
            ->withoutVariations()
            ->slot('restricted');
    })->throws(FileUnacceptableForCollection::class);

    it('accepts file passing custom acceptor', function () {
        $model = adderTestModel();
        $model->addMediaSlot('restricted')
            ->acceptsFile(fn (UploadedFile $file) => str_contains($file->getClientOriginalName(), 'photo'));

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->withoutVariations()
            ->slot('restricted');

        expect($media)->toBeInstanceOf(Media::class);
    });
});

/* =================================================================
 * Slot size limit
 * ================================================================= */

describe('slot size limit', function () {

    it('enforces singleFile by removing old media', function () {
        $model = adderTestModel();
        $model->addMediaSlot('avatar')
            ->singleFile();

        $file1 = UploadedFile::fake()->image('first.jpg', 100, 100);
        $first_media = $model->addMedia($file1)
            ->withoutVariations()
            ->slot('avatar');

        $file2 = UploadedFile::fake()->image('second.jpg', 200, 200);
        $second_media = $model->addMedia($file2)
            ->withoutVariations()
            ->slot('avatar');

        // First media should have been removed
        expect(Media::query()->find($first_media->id))->toBeNull()
            ->and(Media::withTrashed()->find($first_media->id)?->trashed())->toBeTrue();

        // Second media should exist
        expect(Media::find($second_media->id))->not->toBeNull();
    });

    it('keeps the existing one-to-one file when its replacement upload fails', function () {
        $model = adderTestModel();
        $model->addMediaSlot('identity-document')->oneToOne();

        $existing = $model->addMedia(UploadedFile::fake()->createWithContent('identity.txt', 'existing'))
            ->withoutVariations()
            ->slot('identity-document');

        expect(fn () => $model
            ->addMedia(UploadedFile::fake()->createWithContent('empty.txt', ''))
            ->withoutVariations()
            ->slot('identity-document'))
            ->toThrow(FileUnacceptableForCollection::class);

        $model->unsetRelation('media');

        expect(Media::query()->find($existing->id))->not->toBeNull()
            ->and($model->getFirstMedia('identity-document')?->is($existing))->toBeTrue();
    });

    it('rolls back the complete one-to-one swap when old-media removal fails', function () {
        $model = adderTestModel();
        $model->addMediaSlot('identity-document')->oneToOne();
        $existing = $model->addMedia(
            UploadedFile::fake()->createWithContent('identity.txt', 'existing'),
        )->withoutVariations()->slot('identity-document');
        $existingPath = $existing->buildPath();
        $replacementPath = null;

        Media::deleting(function (Media $deleting) use (
            $existing,
            $model,
            &$replacementPath,
        ): void {
            if (! $deleting->is($existing)) {
                return;
            }

            $replacementId = MediaAssociation::query()
                ->where('associable_type', $model->getMorphClass())
                ->where('associable_id', $model->getKey())
                ->where('collection', 'identity-document')
                ->where('media_id', '!=', $existing->id)
                ->value('media_id');
            $replacement = is_string($replacementId)
                ? Media::query()->find($replacementId)
                : null;
            $replacementPath = $replacement?->buildPath();

            throw new RuntimeException('Simulated removal failure.');
        });

        expect(fn () => $model
            ->addMedia(UploadedFile::fake()->createWithContent('replacement.txt', 'replacement'))
            ->withoutVariations()
            ->slot('identity-document'))
            ->toThrow(RuntimeException::class, 'Simulated removal failure.');

        $model->unsetRelation('media');

        expect($replacementPath)->toBeString()
            ->and(Media::query()->find($existing->id)?->is($existing))->toBeTrue()
            ->and($model->getMedia('identity-document')->pluck('id')->all())
            ->toBe([$existing->id])
            ->and(Media::query()->count())->toBe(1);
        Storage::disk('public')->assertExists($existingPath);
        Storage::disk('public')->assertMissing($replacementPath);
    });

    it('restores the previous one-to-one item and its file on an outer rollback', function () {
        $model = adderTestModel();
        $model->addMediaSlot('avatar')->oneToOne();
        $existing = $model->addMedia(
            UploadedFile::fake()->createWithContent('old-avatar.txt', 'old-avatar'),
        )->withoutVariations()->slot('avatar');
        $existingPath = $existing->buildPath();
        $startingTransactionLevel = DB::transactionLevel();
        $replacement = null;
        $replacementPath = null;
        DB::beginTransaction();

        try {
            $replacement = $model->addMedia(
                UploadedFile::fake()->createWithContent('new-avatar.txt', 'new-avatar'),
            )->withoutVariations()->slot('avatar');
            $replacementPath = $replacement->buildPath();

            expect(Media::query()->find($existing->id))->toBeNull();
            Storage::disk('public')->assertExists($existingPath);
            Storage::disk('public')->assertExists($replacementPath);

            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > $startingTransactionLevel) {
                DB::rollBack($startingTransactionLevel);
            }
        }

        $model->unsetRelation('media');

        expect($replacement)->toBeInstanceOf(Media::class)
            ->and($replacementPath)->toBeString()
            ->and(Media::query()->find($existing->id)?->is($existing))->toBeTrue()
            ->and(Media::withTrashed()->find($replacement->id))->toBeNull()
            ->and($model->getMedia('avatar')->pluck('id')->all())->toBe([$existing->id]);
        Storage::disk('public')->assertExists($existingPath);
        Storage::disk('public')->assertMissing($replacementPath);
    });

    it('serializes size-limited uploads by owner and collection', function () {
        config([
            'media.mutation_lock.enabled' => true,
            'media.mutation_lock.store' => 'array',
            'media.mutation_lock.seconds' => 10,
            'media.mutation_lock.wait_seconds' => 0,
        ]);
        $model = adderTestModel();
        $otherModel = adderTestModel(['name' => 'Other lock owner']);
        $model->addMediaSlot('avatar')->oneToOne();
        $model->addMediaSlot('documents')->oneToOne();
        $otherModel->addMediaSlot('avatar')->oneToOne();
        $competingLock = Cache::store('array')->lock(
            adderOwnerCollectionLockKey($model, 'avatar'),
            10,
        );

        expect($competingLock->get())->toBeTrue();

        try {
            expect(fn () => $model
                ->addMedia(UploadedFile::fake()->createWithContent('blocked.txt', 'blocked'))
                ->withoutVariations()
                ->slot('avatar'))
                ->toThrow(MediaUploadException::class, 'Timed out');

            $differentCollection = $model->addMedia(
                UploadedFile::fake()->createWithContent('document.txt', 'document'),
            )->withoutVariations()->slot('documents');
            $differentOwner = $otherModel->addMedia(
                UploadedFile::fake()->createWithContent('avatar.txt', 'avatar'),
            )->withoutVariations()->slot('avatar');
        } finally {
            $competingLock->release();
        }

        $afterRelease = $model->addMedia(
            UploadedFile::fake()->createWithContent('available.txt', 'available'),
        )->withoutVariations()->slot('avatar');

        expect($model->getMedia('documents')->pluck('id')->all())
            ->toBe([$differentCollection->id])
            ->and($otherModel->getMedia('avatar')->pluck('id')->all())
            ->toBe([$differentOwner->id])
            ->and($model->getMedia('avatar')->pluck('id')->all())
            ->toBe([$afterRelease->id]);
    });

    it('acquires only missing identities when media mutation locks are nested', function () {
        config([
            'media.mutation_lock.enabled' => true,
            'media.mutation_lock.store' => 'array',
            'media.mutation_lock.seconds' => 10,
            'media.mutation_lock.wait_seconds' => 0,
        ]);
        $lock = app(MediaMutationLock::class);
        $availabilityDuringInner = [];
        $availabilityAfterInner = [];

        $result = $lock->execute(
            'already-held',
            function () use ($lock, &$availabilityDuringInner, &$availabilityAfterInner): string {
                $result = $lock->executeMany(
                    ['already-held', 'new-identity'],
                    function () use (&$availabilityDuringInner): string {
                        $availabilityDuringInner = [
                            'already-held' => canAcquireAdderMutationLock('already-held'),
                            'new-identity' => canAcquireAdderMutationLock('new-identity'),
                        ];

                        return 'completed';
                    },
                );
                $availabilityAfterInner = [
                    'already-held' => canAcquireAdderMutationLock('already-held'),
                    'new-identity' => canAcquireAdderMutationLock('new-identity'),
                ];

                return $result;
            },
        );

        expect($result)->toBe('completed')
            ->and($availabilityDuringInner)->toBe([
                'already-held' => false,
                'new-identity' => false,
            ])
            ->and($availabilityAfterInner)->toBe([
                'already-held' => false,
                'new-identity' => true,
            ])
            ->and(canAcquireAdderMutationLock('already-held'))->toBeTrue()
            ->and(canAcquireAdderMutationLock('new-identity'))->toBeTrue();
    });

    it('releases partially acquired mutation locks when a later identity times out', function () {
        config([
            'media.mutation_lock.enabled' => true,
            'media.mutation_lock.store' => 'array',
            'media.mutation_lock.seconds' => 10,
            'media.mutation_lock.wait_seconds' => 0,
        ]);
        $blocked = Cache::store('array')->lock(
            adderMutationLockKey('z-blocked'),
            10,
        );

        expect($blocked->get())->toBeTrue();

        try {
            expect(fn () => app(MediaMutationLock::class)->executeMany(
                ['a-acquired-first', 'z-blocked'],
                static fn (): null => null,
            ))->toThrow(MediaUploadException::class, 'Timed out');

            expect(canAcquireAdderMutationLock('a-acquired-first'))->toBeTrue();
        } finally {
            $blocked->release();
        }
    });

    it('holds mutation locks until the real database transaction completes', function () {
        config([
            'media.mutation_lock.enabled' => true,
            'media.mutation_lock.store' => 'array',
            'media.mutation_lock.seconds' => 10,
            'media.mutation_lock.wait_seconds' => 0,
        ]);
        $lock = app(MediaMutationLock::class);
        $originalConnection = DB::getDefaultConnection();
        $isolatedConnection = 'media-mutation-lock-test';
        config([
            "database.connections.{$isolatedConnection}" => config(
                "database.connections.{$originalConnection}",
            ),
        ]);
        DB::setDefaultConnection($isolatedConnection);

        try {
            DB::beginTransaction();
            $lock->execute('commit-bound', static fn (): null => null);
            expect(canAcquireAdderMutationLock('commit-bound'))->toBeFalse();
            DB::commit();
            expect(canAcquireAdderMutationLock('commit-bound'))->toBeTrue();

            DB::beginTransaction();
            $lock->execute('rollback-bound', static fn (): null => null);
            expect(canAcquireAdderMutationLock('rollback-bound'))->toBeFalse();
            DB::rollBack();
            expect(canAcquireAdderMutationLock('rollback-bound'))->toBeTrue();
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge($isolatedConnection);
        }
    });

    it('enforces onlyKeepLatest limit', function () {
        $model = adderTestModel();
        $model->addMediaSlot('gallery')
            ->onlyKeepLatest(2);

        $media_ids = [];
        $sizes = [100, 200, 300];
        Carbon::setTestNow('2026-08-07 12:00:00');

        try {
            for ($i = 0; $i < 2; $i++) {
                $file = UploadedFile::fake()->image("file{$i}.jpg", $sizes[$i], $sizes[$i]);
                $media = $model->addMedia($file)
                    ->withoutVariations()
                    ->slot('gallery');
                $media_ids[] = $media->id;
            }

            MediaAssociation::query()
                ->where('media_id', $media_ids[0])
                ->update(['order' => 1]);
            MediaAssociation::query()
                ->where('media_id', $media_ids[1])
                ->update(['order' => 0]);

            $latest = $model->addMedia(
                UploadedFile::fake()->image('file2.jpg', $sizes[2], $sizes[2]),
            )->withoutVariations()->slot('gallery');
            $media_ids[] = $latest->id;
        } finally {
            Carbon::setTestNow();
        }

        // First file should have been removed
        expect(Media::query()->find($media_ids[0]))->toBeNull()
            ->and(Media::withTrashed()->find($media_ids[0])?->trashed())->toBeTrue();

        // Last two should exist
        expect(Media::find($media_ids[1]))->not->toBeNull()
            ->and(Media::find($media_ids[2]))->not->toBeNull();
    });

    it('detaches shared single-file media instead of deleting it when another owner still uses it', function () {
        $firstModel = adderTestModel(['name' => 'First Single Owner']);
        $secondModel = adderTestModel(['name' => 'Second Single Owner']);

        $firstModel->addMediaSlot('avatar')
            ->singleFile()
            ->publicReusable();
        $secondModel->addMediaSlot('avatar')
            ->singleFile()
            ->publicReusable();

        $sharedMedia = $firstModel->addMedia(UploadedFile::fake()->createWithContent('shared-avatar.txt', 'shared-avatar'))
            ->withoutVariations()
            ->slot('avatar');

        $secondModel->addMedia(UploadedFile::fake()->createWithContent('shared-avatar.txt', 'shared-avatar'))
            ->withoutVariations()
            ->slot('avatar');

        $replacementMedia = $firstModel->addMedia(UploadedFile::fake()->createWithContent('replacement-avatar.txt', 'replacement-avatar'))
            ->withoutVariations()
            ->slot('avatar');

        $firstModel->unsetRelation('media');
        $secondModel->unsetRelation('media');

        expect($replacementMedia->id)->not->toBe($sharedMedia->id);
        expect(Media::find($sharedMedia->id))->not->toBeNull();
        expect($firstModel->getMedia('avatar')->pluck('id')->all())->toBe([$replacementMedia->id]);
        expect($secondModel->getMedia('avatar')->pluck('id')->all())->toBe([$sharedMedia->id]);
    });
});

/* =================================================================
 * slotOnCloudDisk()
 * ================================================================= */

describe('slotOnCloudDisk', function () {

    it('uses the configured cloud disk', function () {
        Storage::fake('s3');
        config([
            'filesystems.cloud' => 's3',
            'media.allowed_disks' => ['public', 's3'],
        ]);

        $model = adderTestModel();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $media = $model->addMedia($file)
            ->withoutVariations()
            ->slotOnCloudDisk('gallery');

        expect($media->disk)->toBe('s3');
    });
});
