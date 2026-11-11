<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaTemporaryFileRegistry;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Stubs\OwnerSlotWorkflowModel;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    Storage::fake('public');
});

function uploadToSlot(UploadedFile $file, MediaSlot $slot, array $tags = [], ?string $fileName = null): Media
{
    return app(MediaUploader::class)->upload(
        SystemActor::Background,
        $file,
        'public',
        TestMediaModel::query()->create(['name' => 'Slot owner']),
        $slot,
        $fileName ?? $file->getClientOriginalName(),
        tags: $tags,
        skipAutoVariations: true,
    );
}

it('converts and caps the stored original when the slot declares it', function (): void {
    $slot = (new MediaSlot('cover'))->acceptsMimeTypes(['image/jpeg'])->convertTo('webp')->maxSize(40);

    $media = uploadToSlot(UploadedFile::fake()->image('upload.jpg', 120, 60), $slot, fileName: 'Cover Photo.jpg');
    $stored = Storage::disk('public')->get($media->buildPath());
    [$width, $height] = getimagesizefromstring((string) $stored);

    expect($media->extension)->toBe('webp')
        ->and($media->mime_type)->toBe('image/webp')
        ->and($media->filename)->toBe('Cover-Photo.webp')
        ->and($media->digest)->toBe(hash('sha256', (string) $stored))
        ->and($media->size)->toBe(strlen((string) $stored))
        ->and([$width, $height])->toBe([40, 20])
        ->and(app(MediaTemporaryFileRegistry::class)->count())->toBe(0);
});

it('applies a quality-only slot option without changing the format', function (): void {
    $file = UploadedFile::fake()->image('photo.jpg', 200, 200);
    $media = uploadToSlot($file, (new MediaSlot('cover'))->withQuality(10));

    expect($media->extension)->toBe('jpg')
        ->and($media->digest)->not->toBe(hash_file('sha256', $file->getRealPath()));
});

it('validates the submitted file, not the converted output, against the slot', function (): void {
    $slot = (new MediaSlot('cover'))->acceptsMimeTypes(['image/png'])->convertTo('webp');

    expect(fn () => uploadToSlot(UploadedFile::fake()->image('photo.jpg', 20, 20), $slot))
        ->toThrow(FileUnacceptableForCollection::class);
    expect(uploadToSlot(UploadedFile::fake()->image('photo.png', 20, 20), $slot)->mime_type)->toBe('image/webp');
});

it('stores files the slot conversion does not apply to unchanged', function (): void {
    $slot = (new MediaSlot('files'))->convertTo('webp')->maxSize(10);
    $media = uploadToSlot(UploadedFile::fake()->createWithContent('notes.txt', 'plain text'), $slot);

    expect($media->extension)->toBe('txt')
        ->and(Storage::disk('public')->get($media->buildPath()))->toBe('plain text');
});

it('adds the slot default tags to an upload', function (): void {
    $slot = (new MediaSlot('gallery'))->withTags(['gallery', 'shared']);
    $media = uploadToSlot(UploadedFile::fake()->createWithContent('tagged.txt', 'tagged'), $slot, ['shared', 'custom']);

    expect($media->tags)->toBe(['gallery', 'shared', 'custom']);
});

it('keeps the source bytes when copying into a slot that converts originals', function (): void {
    $actor = OwnerSlotWorkflowModel::query()->create(['name' => 'Actor']);
    $source = uploadToSlot(UploadedFile::fake()->image('source.png', 64, 64), (new MediaSlot('plain'))->privateExclusive());
    $source->forceFill(['uploaded_by' => (string) $actor->getKey(), 'uploaded_by_type' => $actor->getMorphClass()])->save();
    $sourceOwner = OwnerSlotWorkflowModel::query()->create(['name' => 'Source']);
    MediaAssociation::query()->create(['media_id' => $source->id, 'associable_type' => $sourceOwner->getMorphClass(), 'associable_id' => (string) $sourceOwner->getKey(), 'collection' => 'converted', 'order' => 0, 'metadata' => ['slot' => 'converted']]);
    $destination = OwnerSlotWorkflowModel::query()->create(['name' => 'Destination']);

    $copy = app(MediaAttachments::class)->copySlot($actor, $destination, 'converted', $source->id);
    $copied = Media::query()->findOrFail($copy->id);

    expect($copied->digest)->toBe($source->digest)->and($copied->extension)->toBe('png');
});
