<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaLifecycleService;
use Nvl\Media\Tests\Stubs\TestMediaModel;

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
    ]);

    $this->service = app(MediaLifecycleService::class);
});

function lifecycleModel(array $overrides = []): TestMediaModel
{
    return TestMediaModel::create(array_merge([
        'name' => 'Lifecycle Test Model',
    ], $overrides));
}

function attachMedia(TestMediaModel $model, int $count = 1, string $collection = 'default'): array
{
    $items = [];

    for ($i = 0; $i < $count; $i++) {
        // Vary dimensions to produce unique digests (dedup prevention)
        $file = UploadedFile::fake()->image("photo-{$i}.jpg", 100 + $i, 100 + $i);
        $media = $model->addMedia($file)->withoutVariations()->allowingDuplicates()->slot($collection);
        $items[] = $media;
    }

    return $items;
}

/* =================================================================
 * clearCollection
 * ================================================================= */

describe('clearCollection', function () {

    it('deletes all media in a collection', function () {
        $model = lifecycleModel();
        attachMedia($model, 3, 'gallery');

        expect($model->getMedia('gallery'))->toHaveCount(3);

        $this->service->clearCollection($model, 'gallery');

        $model->unsetRelation('media');
        expect($model->getMedia('gallery'))->toHaveCount(0);
    });

    it('does not affect other collections', function () {
        $model = lifecycleModel();
        attachMedia($model, 2, 'gallery');
        attachMedia($model, 1, 'avatar');

        $this->service->clearCollection($model, 'gallery');

        $model->unsetRelation('media');
        expect($model->getMedia('gallery'))->toHaveCount(0);
        expect($model->getMedia('avatar'))->toHaveCount(1);
    });

    it('detaches shared media without deleting rows still used by another owner', function () {
        $firstModel = lifecycleModel(['name' => 'First Owner']);
        $secondModel = lifecycleModel(['name' => 'Second Owner']);

        $firstModel->addMediaSlot('gallery')->publicReusable();
        $secondModel->addMediaSlot('gallery')->publicReusable();

        $sharedFile = UploadedFile::fake()->createWithContent('shared.txt', 'shared-gallery-media');
        $sharedMedia = $firstModel->addMedia($sharedFile)
            ->withoutVariations()
            ->slot('gallery');

        $secondModel->addMedia(UploadedFile::fake()->createWithContent('shared.txt', 'shared-gallery-media'))
            ->withoutVariations()
            ->slot('gallery');

        $this->service->clearCollection($firstModel, 'gallery');

        $firstModel->unsetRelation('media');
        $secondModel->unsetRelation('media');

        expect($firstModel->getMedia('gallery'))->toHaveCount(0);
        expect($secondModel->getMedia('gallery'))->toHaveCount(1);
        expect(Media::find($sharedMedia->id))->not->toBeNull();
    });
});

/* =================================================================
 * clearCollectionExcept
 * ================================================================= */

describe('clearCollectionExcept', function () {

    it('preserves specified media IDs', function () {
        $model = lifecycleModel();
        $items = attachMedia($model, 3, 'gallery');
        $keepId = $items[1]->id;

        $this->service->clearCollectionExcept($model, 'gallery', [$keepId]);

        $model->unsetRelation('media');
        $remaining = $model->getMedia('gallery');
        expect($remaining)->toHaveCount(1);
        expect($remaining->first()->id)->toBe($keepId);
    });

    it('accepts Media instances in except list', function () {
        $model = lifecycleModel();
        $items = attachMedia($model, 2, 'gallery');

        $this->service->clearCollectionExcept($model, 'gallery', [$items[0]]);

        $model->unsetRelation('media');
        expect($model->getMedia('gallery'))->toHaveCount(1);
    });
});

/* =================================================================
 * deleteMedia
 * ================================================================= */

describe('deleteMedia', function () {

    it('deletes by Media instance', function () {
        $model = lifecycleModel();
        $items = attachMedia($model, 1);

        $this->service->deleteMedia($items[0]);

        expect(Media::find($items[0]->id))->toBeNull();
    });

    it('deletes by string ID', function () {
        $model = lifecycleModel();
        $items = attachMedia($model, 1);
        $id = $items[0]->id;

        $this->service->deleteMedia($id);

        expect(Media::find($id))->toBeNull();
    });
});

/* =================================================================
 * deleteAll
 * ================================================================= */

describe('deleteAll', function () {

    it('removes all media from a model', function () {
        $model = lifecycleModel();
        attachMedia($model, 2, 'gallery');
        attachMedia($model, 1, 'avatar');

        $this->service->deleteAll($model);

        $model->unsetRelation('media');
        expect($model->media()->count())->toBe(0);
    });
});

/* =================================================================
 * detach
 * ================================================================= */

describe('detach', function () {

    it('removes pivot without deleting file', function () {
        $model = lifecycleModel();
        $items = attachMedia($model, 1, 'gallery');
        $mediaId = $items[0]->id;

        $this->service->detach($items[0], $model, 'gallery');

        $model->unsetRelation('media');
        expect($model->getMedia('gallery'))->toHaveCount(0);

        // Media record still exists (not deleted, only detached)
        expect(Media::find($mediaId))->not->toBeNull();
    });
});
