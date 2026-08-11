<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\MediaAdder;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Media\Tests\Stubs\TestMediaUser;

function testModelClass(): string
{
    return TestMediaModel::class;
}

function createTestModel(array $overrides = []): TestMediaModel
{
    return TestMediaModel::create(array_merge([
        'name' => 'Test Model',
    ], $overrides));
}

function createTraitMedia(array $overrides = []): Media
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

function attachMediaToModel(TestMediaModel $model, Media $media, string $collection = 'default', int $order = 0): void
{
    MediaAssociation::create([
        'media_id' => $media->id,
        'associable_type' => $model->getMorphClass(),
        'associable_id' => $model->id,
        'collection' => $collection,
        'order' => $order,
    ]);
}

/* =================================================================
 * media() relationship
 * ================================================================= */

describe('media relationship', function () {

    it('returns media via morph-to-many pivot', function () {
        $model = createTestModel();
        $media = createTraitMedia();

        attachMediaToModel($model, $media, 'avatar');

        $result = $model->media()->get();

        expect($result)->toHaveCount(1)
            ->and($result->first()->id)->toBe($media->id)
            ->and($result->first()->pivot->collection)->toBe('avatar');
    });

    it('includes pivot data', function () {
        $model = createTestModel();
        $media = createTraitMedia();

        MediaAssociation::create([
            'media_id' => $media->id,
            'associable_type' => $model->getMorphClass(),
            'associable_id' => $model->id,
            'collection' => 'gallery',
            'locale' => 'en',
            'order' => 3,
        ]);

        $result = $model->media()->first();

        expect($result->pivot->collection)->toBe('gallery')
            ->and($result->pivot->locale)->toBe('en')
            ->and($result->pivot->order)->toBe(3);
    });

    it('orders by pivot order column', function () {
        $model = createTestModel();
        $media1 = createTraitMedia(['filename' => 'first.jpg']);
        $media2 = createTraitMedia(['filename' => 'second.jpg']);
        $media3 = createTraitMedia(['filename' => 'third.jpg']);

        attachMediaToModel($model, $media3, 'gallery', 2);
        attachMediaToModel($model, $media1, 'gallery', 0);
        attachMediaToModel($model, $media2, 'gallery', 1);

        $result = $model->media()->get();

        expect($result[0]->filename)->toBe('first.jpg')
            ->and($result[1]->filename)->toBe('second.jpg')
            ->and($result[2]->filename)->toBe('third.jpg');
    });

    it('supports integer owner keys through string-compatible associations', function () {
        $owner = TestMediaUser::query()->create([
            'name' => 'Integer Owner',
            'email' => 'integer-owner@example.test',
            'password' => 'secret',
        ]);
        $media = createTraitMedia();

        MediaAssociation::query()->create([
            'media_id' => $media->id,
            'associable_type' => $owner->getMorphClass(),
            'associable_id' => (string) $owner->getKey(),
            'collection' => 'documents',
            'order' => 0,
        ]);

        $owner->load('media', 'mediaAssociations');

        expect($owner->media)->toHaveCount(1)
            ->and($owner->mediaAssociations)->toHaveCount(1)
            ->and(TestMediaUser::query()->whereHas('media')->count())->toBe(1)
            ->and(TestMediaUser::query()->whereHas('mediaAssociations')->count())->toBe(1);
    });
});

/* =================================================================
 * mediaAssociations()
 * ================================================================= */

describe('mediaAssociations', function () {

    it('returns direct morph-many association records', function () {
        $model = createTestModel();
        $media = createTraitMedia();

        attachMediaToModel($model, $media, 'avatar');

        $assocs = $model->mediaAssociations;

        expect($assocs)->toHaveCount(1)
            ->and($assocs->first())->toBeInstanceOf(MediaAssociation::class)
            ->and($assocs->first()->media_id)->toBe($media->id);
    });
});

/* =================================================================
 * getMedia()
 * ================================================================= */

describe('getMedia', function () {

    it('retrieves media for a specific collection', function () {
        $model = createTestModel();
        $avatar = createTraitMedia(['filename' => 'avatar.jpg']);
        $gallery = createTraitMedia(['filename' => 'gallery.jpg']);

        attachMediaToModel($model, $avatar, 'avatar');
        attachMediaToModel($model, $gallery, 'gallery');

        $result = $model->getMedia('avatar');

        expect($result)->toHaveCount(1)
            ->and($result->first()->filename)->toBe('avatar.jpg');
    });

    it('returns empty collection when no media in collection', function () {
        $model = createTestModel();

        $result = $model->getMedia('empty');

        expect($result)->toHaveCount(0);
    });

    it('filters with callable', function () {
        $model = createTestModel();
        $jpg = createTraitMedia(['filename' => 'photo.jpg', 'extension' => 'jpg']);
        $png = createTraitMedia(['filename' => 'icon.png', 'extension' => 'png', 'mime_type' => 'image/png']);

        attachMediaToModel($model, $jpg, 'gallery');
        attachMediaToModel($model, $png, 'gallery');

        $result = $model->getMedia('gallery', fn (Media $m) => $m->extension === 'png');

        expect($result)->toHaveCount(1)
            ->and($result->first()->filename)->toBe('icon.png');
    });

    it('filters with array', function () {
        $model = createTestModel();
        $m1 = createTraitMedia(['filename' => 'one.jpg', 'extension' => 'jpg']);
        $m2 = createTraitMedia(['filename' => 'two.png', 'extension' => 'png', 'mime_type' => 'image/png']);

        attachMediaToModel($model, $m1, 'gallery');
        attachMediaToModel($model, $m2, 'gallery');

        $result = $model->getMedia('gallery', ['extension' => 'png']);

        expect($result)->toHaveCount(1)
            ->and($result->first()->filename)->toBe('two.png');
    });
});

/* =================================================================
 * getFirstMedia() / getLastMedia()
 * ================================================================= */

describe('getFirstMedia and getLastMedia', function () {

    it('returns first media in collection', function () {
        $model = createTestModel();
        $first = createTraitMedia(['filename' => 'first.jpg']);
        $second = createTraitMedia(['filename' => 'second.jpg']);

        attachMediaToModel($model, $first, 'gallery', 0);
        attachMediaToModel($model, $second, 'gallery', 1);

        expect($model->getFirstMedia('gallery')->filename)->toBe('first.jpg');
    });

    it('returns last media in collection', function () {
        $model = createTestModel();
        $first = createTraitMedia(['filename' => 'first.jpg']);
        $second = createTraitMedia(['filename' => 'second.jpg']);

        attachMediaToModel($model, $first, 'gallery', 0);
        attachMediaToModel($model, $second, 'gallery', 1);

        expect($model->getLastMedia('gallery')->filename)->toBe('second.jpg');
    });

    it('returns null when collection is empty', function () {
        $model = createTestModel();

        expect($model->getFirstMedia('empty'))->toBeNull()
            ->and($model->getLastMedia('empty'))->toBeNull();
    });
});

/* =================================================================
 * hasMedia()
 * ================================================================= */

describe('hasMedia', function () {

    it('returns true when media exists in collection', function () {
        $model = createTestModel();
        $media = createTraitMedia();

        attachMediaToModel($model, $media, 'avatar');

        expect($model->hasMedia('avatar'))->toBeTrue();
    });

    it('returns false when collection is empty', function () {
        $model = createTestModel();

        expect($model->hasMedia('avatar'))->toBeFalse();
    });

    it('supports callable filter', function () {
        $model = createTestModel();
        $media = createTraitMedia(['extension' => 'jpg']);

        attachMediaToModel($model, $media, 'gallery');

        expect($model->hasMedia('gallery', fn (Media $m) => $m->extension === 'jpg'))->toBeTrue()
            ->and($model->hasMedia('gallery', fn (Media $m) => $m->extension === 'png'))->toBeFalse();
    });
});

/* =================================================================
 * addMedia() / copyMedia()
 * ================================================================= */

describe('addMedia and copyMedia', function () {

    it('returns a MediaAdder instance', function () {
        $model = createTestModel();
        $file = UploadedFile::fake()->image('photo.jpg');

        $adder = $model->addMedia($file);

        expect($adder)->toBeInstanceOf(MediaAdder::class);
    });

    it('copyMedia returns a MediaAdder instance', function () {
        $model = createTestModel();
        $file = UploadedFile::fake()->image('photo.jpg');

        $adder = $model->copyMedia($file);

        expect($adder)->toBeInstanceOf(MediaAdder::class);
    });
});

/* =================================================================
 * Slot Registration
 * ================================================================= */

describe('slot registration', function () {

    it('registers and retrieves media slots', function () {
        $model = createTestModel();

        $collection = $model->addMediaSlot('gallery');

        expect($collection)->toBeInstanceOf(MediaSlot::class);
        expect($model->getMediaSlot('gallery'))->toBe($collection);
    });

    it('returns null for unregistered slot', function () {
        $model = createTestModel();

        expect($model->getMediaSlot('nonexistent'))->toBeNull();
    });

    it('returns all registered slots', function () {
        $model = createTestModel();

        $model->addMediaSlot('avatar');
        $model->addMediaSlot('gallery');

        $all = $model->getRegisteredMediaSlots();

        expect($all)->toHaveCount(2)
            ->and($all->keys()->toArray())->toBe(['avatar', 'gallery']);
    });
});

/* =================================================================
 * Conversion Registration
 * ================================================================= */

describe('conversion registration', function () {

    it('registers and retrieves conversion definitions', function () {
        $model = createTestModel();

        $definition = $model->addMediaConversion('thumb');

        expect($definition)->toBeInstanceOf(ConversionDefinition::class)
            ->and($definition->name)->toBe('thumb');

        $all = $model->getModelConversions();

        expect($all)->toHaveCount(1)
            ->and($all['thumb'])->toBe($definition);
    });
});

/* =================================================================
 * clearMediaCollection()
 * ================================================================= */

describe('clearMediaCollection', function () {

    it('deletes all media in a collection', function () {
        Storage::fake('public');

        $model = createTestModel();
        $m1 = createTraitMedia(['filename' => 'a.jpg']);
        $m2 = createTraitMedia(['filename' => 'b.jpg']);

        attachMediaToModel($model, $m1, 'gallery');
        attachMediaToModel($model, $m2, 'gallery');

        expect($model->getMedia('gallery'))->toHaveCount(2);

        $model->clearMediaCollection('gallery');

        // Media records should be force-deleted
        expect(Media::whereIn('id', [$m1->id, $m2->id])->count())->toBe(0);
    });

    it('does not affect other collections', function () {
        Storage::fake('public');

        $model = createTestModel();
        $avatar = createTraitMedia(['filename' => 'avatar.jpg']);
        $gallery = createTraitMedia(['filename' => 'gallery.jpg']);

        attachMediaToModel($model, $avatar, 'avatar');
        attachMediaToModel($model, $gallery, 'gallery');

        $model->clearMediaCollection('gallery');

        expect(Media::find($avatar->id))->not->toBeNull()
            ->and(Media::query()->find($gallery->id))->toBeNull()
            ->and(Media::withTrashed()->find($gallery->id)?->trashed())->toBeTrue();
    });
});

/* =================================================================
 * clearMediaCollectionExcept()
 * ================================================================= */

describe('clearMediaCollectionExcept', function () {

    it('keeps specified media and deletes the rest', function () {
        Storage::fake('public');

        $model = createTestModel();
        $keep = createTraitMedia(['filename' => 'keep.jpg']);
        $remove = createTraitMedia(['filename' => 'remove.jpg']);

        attachMediaToModel($model, $keep, 'gallery');
        attachMediaToModel($model, $remove, 'gallery');

        $model->clearMediaCollectionExcept('gallery', [$keep]);

        expect(Media::find($keep->id))->not->toBeNull()
            ->and(Media::query()->find($remove->id))->toBeNull()
            ->and(Media::withTrashed()->find($remove->id)?->trashed())->toBeTrue();
    });

    it('accepts array of IDs', function () {
        Storage::fake('public');

        $model = createTestModel();
        $keep = createTraitMedia(['filename' => 'keep.jpg']);
        $remove = createTraitMedia(['filename' => 'remove.jpg']);

        attachMediaToModel($model, $keep, 'gallery');
        attachMediaToModel($model, $remove, 'gallery');

        $model->clearMediaCollectionExcept('gallery', [$keep->id]);

        expect(Media::find($keep->id))->not->toBeNull()
            ->and(Media::query()->find($remove->id))->toBeNull()
            ->and(Media::withTrashed()->find($remove->id)?->trashed())->toBeTrue();
    });
});

/* =================================================================
 * deleteMedia() / deleteAllMedia()
 * ================================================================= */

describe('deleteMedia and deleteAllMedia', function () {

    it('deletes a single media item', function () {
        Storage::fake('public');

        $model = createTestModel();
        $media = createTraitMedia();

        attachMediaToModel($model, $media, 'avatar');

        $model->deleteMedia($media);

        expect(Media::query()->find($media->id))->toBeNull()
            ->and(Media::withTrashed()->find($media->id)?->trashed())->toBeTrue();
    });

    it('deletes all media associated with the model', function () {
        Storage::fake('public');

        $model = createTestModel();
        $m1 = createTraitMedia();
        $m2 = createTraitMedia();

        attachMediaToModel($model, $m1, 'avatar');
        attachMediaToModel($model, $m2, 'gallery');

        $model->deleteAllMedia();

        expect(Media::query()->whereIn('id', [$m1->id, $m2->id])->count())->toBe(0)
            ->and(Media::withTrashed()->whereIn('id', [$m1->id, $m2->id])->count())->toBe(2);
    });

    it('preserves media while an owning model is soft deleted', function () {
        Storage::fake('public');
        config(['media.delete_media_on_model_delete' => true]);

        $model = createTestModel();
        $media = createTraitMedia();
        attachMediaToModel($model, $media, 'avatar');

        $model->delete();

        expect(Media::find($media->id))->not->toBeNull();
    });

    it('deletes media when an owner without soft deletes is deleted', function () {
        Storage::fake('public');
        config(['media.delete_media_on_model_delete' => true]);

        $owner = TestMediaUser::query()->create([
            'name' => 'Hard-delete owner',
            'email' => 'hard-delete@example.test',
            'password' => 'secret',
        ]);
        $media = createTraitMedia();
        MediaAssociation::query()->create([
            'media_id' => $media->id,
            'associable_type' => $owner->getMorphClass(),
            'associable_id' => (string) $owner->getKey(),
            'collection' => 'avatar',
            'order' => 0,
        ]);
        Storage::disk('public')->put($media->buildPath(), 'hard-delete-media');

        $owner->delete();

        expect(Media::query()->find($media->id))->toBeNull()
            ->and(Media::withTrashed()->find($media->id)?->trashed())->toBeTrue();
        Storage::disk('public')->assertMissing($media->buildPath());
    });

    it('keeps media intact when a later listener prevents a hard owner deletion', function () {
        Storage::fake('public');
        config(['media.delete_media_on_model_delete' => true]);

        $owner = TestMediaUser::query()->create([
            'name' => 'Rejected hard-delete owner',
            'email' => 'rejected-hard-delete@example.test',
            'password' => 'secret',
        ]);
        $media = createTraitMedia([
            'filename' => 'preserved.txt',
            'hash' => 'preserved.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'type' => MediaType::DOCUMENT,
        ]);
        MediaAssociation::query()->create([
            'media_id' => $media->id,
            'associable_type' => $owner->getMorphClass(),
            'associable_id' => (string) $owner->getKey(),
            'collection' => 'documents',
            'order' => 0,
        ]);
        Storage::disk('public')->put($media->buildPath(), 'preserved');

        TestMediaUser::deleting(static function (TestMediaUser $deleting) use ($owner): void {
            if ($deleting->is($owner)) {
                throw new RuntimeException('Owner deletion rejected.');
            }
        });

        expect(fn () => $owner->delete())
            ->toThrow(RuntimeException::class, 'Owner deletion rejected.');

        expect(TestMediaUser::query()->find($owner->getKey()))->not->toBeNull()
            ->and(Media::query()->find($media->id)?->is($media))->toBeTrue()
            ->and(MediaAssociation::query()->where('media_id', $media->id)->exists())->toBeTrue();
        Storage::disk('public')->assertExists($media->buildPath());
    });
});

/* =================================================================
 * detachMedia()
 * ================================================================= */

describe('detachMedia', function () {

    it('removes association without deleting the file', function () {
        $model = createTestModel();
        $media = createTraitMedia();

        attachMediaToModel($model, $media, 'avatar');

        $model->detachMedia($media);

        // Association should be gone
        expect(MediaAssociation::where('media_id', $media->id)->count())->toBe(0);

        // But media record should still exist
        expect(Media::find($media->id))->not->toBeNull();
    });

    it('scopes detach to specific collection', function () {
        $model = createTestModel();
        $media = createTraitMedia();

        attachMediaToModel($model, $media, 'avatar');
        attachMediaToModel($model, $media, 'gallery');

        $model->detachMedia($media, 'avatar');

        expect(MediaAssociation::where('media_id', $media->id)->where('collection', 'avatar')->count())->toBe(0)
            ->and(MediaAssociation::where('media_id', $media->id)->where('collection', 'gallery')->count())->toBe(1);
    });
});

/* =================================================================
 * updateMediaOrder()
 * ================================================================= */

describe('updateMediaOrder', function () {

    it('updates the order of media in a collection', function () {
        $model = createTestModel();
        $m1 = createTraitMedia(['filename' => 'one.jpg']);
        $m2 = createTraitMedia(['filename' => 'two.jpg']);
        $m3 = createTraitMedia(['filename' => 'three.jpg']);

        attachMediaToModel($model, $m1, 'gallery', 0);
        attachMediaToModel($model, $m2, 'gallery', 1);
        attachMediaToModel($model, $m3, 'gallery', 2);

        // Reverse the order
        $model->updateMediaOrder([$m3->id, $m2->id, $m1->id], 'gallery');

        $assoc_m3 = MediaAssociation::where('media_id', $m3->id)->where('collection', 'gallery')->first();
        $assoc_m2 = MediaAssociation::where('media_id', $m2->id)->where('collection', 'gallery')->first();
        $assoc_m1 = MediaAssociation::where('media_id', $m1->id)->where('collection', 'gallery')->first();

        expect($assoc_m3->order)->toBe(0)
            ->and($assoc_m2->order)->toBe(1)
            ->and($assoc_m1->order)->toBe(2);
    });
});

/* =================================================================
 * URL / Path fallbacks
 * ================================================================= */

describe('URL and path fallbacks', function () {

    it('returns empty string when no media and no fallback', function () {
        $model = createTestModel();

        expect($model->getFirstMediaUrl('empty'))->toBe('')
            ->and($model->getFirstMediaPath('empty'))->toBe('')
            ->and($model->getLastMediaUrl('empty'))->toBe('')
            ->and($model->getLastMediaPath('empty'))->toBe('');
    });

    it('returns empty string from getFirstTemporaryUrl when no media', function () {
        $model = createTestModel();

        $result = $model->getFirstTemporaryUrl(now()->addMinutes(5), 'empty');

        expect($result)->toBe('');
    });
});

/* =================================================================
 * deletePreservingMedia()
 * ================================================================= */

describe('deletePreservingMedia', function () {

    it('deletes model but preserves associated media', function () {
        Storage::fake('public');
        config(['media.delete_media_on_model_delete' => true]);

        $model = createTestModel();
        $media = createTraitMedia();
        $model_id = $model->id;

        attachMediaToModel($model, $media, 'avatar');

        $model->deletePreservingMedia();

        expect(TestMediaModel::withTrashed()->find($model_id)->deleted_at)->not->toBeNull();
        expect(Media::find($media->id))->not->toBeNull();
    });
});
