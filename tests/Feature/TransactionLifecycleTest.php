<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\BulkMoveMediaAction;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Actions\ReplaceMediaFileAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaImageTransformer;
use Nvl\Media\Slots\MediaSlot;

beforeEach(function (): void {
    Storage::fake('public');
    config([
        'media.root_folder' => 'media',
        'media.auto_generate_variations' => false,
        'media.output_conversion.enabled' => false,
    ]);
});

function createCommittedTransactionMedia(array $overrides = []): Media
{
    $contents = $overrides['contents'] ?? 'old-content';
    unset($overrides['contents']);
    $media = Media::query()->create(array_merge([
        'filename' => 'old.txt',
        'hash' => 'old-object.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => strlen($contents),
        'disk' => 'public',
        'folder' => 'transactions',
        'is_public' => false,
        'type' => MediaType::DOCUMENT,
        'digest' => hash('sha256', $contents),
    ], $overrides));
    Storage::disk('public')->put($media->buildPath(), $contents);

    return $media;
}

it('removes a newly uploaded object and row when the real outer transaction rolls back', function () {
    $owner = User::query()->forceCreate([
        'name' => 'Outer rollback owner',
        'email' => 'outer-rollback@example.test',
        'password' => 'secret',
    ]);
    DB::beginTransaction();
    $media = app(UploadMediaAction::class)->execute(
        file: UploadedFile::fake()->createWithContent('upload.txt', 'rollback upload'),
        disk: 'public',
        model: $owner,
        slot: new MediaSlot('documents'),
        fileName: 'upload.txt',
        skipAutoVariations: true,
    );
    $path = $media->buildPath();

    Storage::disk('public')->assertExists($path);
    DB::rollBack();

    expect(Media::withTrashed()->find($media->id))->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('retains the old replacement object and deletes only the staged object on outer rollback', function () {
    $media = createCommittedTransactionMedia();
    $oldPath = $media->buildPath();
    DB::beginTransaction();
    $replacement = app(ReplaceMediaFileAction::class)->execute(
        $media,
        UploadedFile::fake()->createWithContent('replacement.txt', 'replacement'),
    );
    $newPath = $replacement->buildPath();

    Storage::disk('public')->assertExists($oldPath);
    Storage::disk('public')->assertExists($newPath);
    DB::rollBack();

    expect($media->fresh()?->hash)->toBe('old-object.txt');
    Storage::disk('public')->assertExists($oldPath);
    Storage::disk('public')->assertMissing($newPath);
});

it('soft-deletes first and removes objects only after the outer commit', function () {
    $media = createCommittedTransactionMedia();
    $path = $media->buildPath();
    DB::beginTransaction();

    app(DeleteMediaAction::class)->execute($media);

    expect(Media::query()->find($media->id))->toBeNull();
    Storage::disk('public')->assertExists($path);
    DB::commit();

    expect(Media::withTrashed()->find($media->id)?->trashed())->toBeTrue();
    Storage::disk('public')->assertMissing($path);
});

it('restores copied move destinations and database paths on outer rollback', function () {
    $media = createCommittedTransactionMedia();
    $oldPath = $media->buildPath();
    $oldVariationPath = Media::storagePath('transactions').'/conversions/custom-object.txt';
    $variation = MediaImageVariation::query()->create([
        'media_id' => $media->id,
        'label' => 'preview',
        'storage_path' => $oldVariationPath,
        'status' => 'available',
        'width' => 100,
        'height' => 100,
        'size' => 9,
        'format' => 'txt',
        'quality' => 80,
        'source_revision' => $media->revision,
        'attempts' => 1,
    ]);
    Storage::disk('public')->put($oldVariationPath, 'variation');
    DB::beginTransaction();

    app(BulkMoveMediaAction::class)->execute([$media->id], 'moved');
    $newPath = Media::storagePath('moved').'/'.$media->hash;
    $newVariationPath = Media::storagePath('moved').'/conversions/custom-object.txt';

    Storage::disk('public')->assertExists($oldPath);
    Storage::disk('public')->assertExists($newPath);
    Storage::disk('public')->assertExists($oldVariationPath);
    Storage::disk('public')->assertExists($newVariationPath);
    expect($variation->fresh()?->storage_path)->toBe($newVariationPath);
    DB::rollBack();

    expect($media->fresh()?->folder)->toBe('transactions');
    expect($variation->fresh()?->storage_path)->toBe($oldVariationPath);
    Storage::disk('public')->assertExists($oldPath);
    Storage::disk('public')->assertMissing($newPath);
    Storage::disk('public')->assertExists($oldVariationPath);
    Storage::disk('public')->assertMissing($newVariationPath);
});

it('rolls back associations created inside an outer transaction', function () {
    $media = createCommittedTransactionMedia();
    $owner = User::query()->forceCreate([
        'name' => 'Attachment rollback owner',
        'email' => 'attachment-rollback@example.test',
        'password' => 'secret',
    ]);
    DB::beginTransaction();

    app(AttachMediaAction::class)->execute(
        $media,
        $owner,
        dispatchVariations: false,
    );

    expect(MediaAssociation::query()->where('media_id', $media->id)->exists())->toBeTrue();
    DB::rollBack();
    expect(MediaAssociation::query()->where('media_id', $media->id)->exists())->toBeFalse();
});

it('removes staged variation output when the outer transaction rolls back', function () {
    $media = createCommittedTransactionMedia([
        'filename' => 'source.jpg',
        'hash' => 'source.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'type' => MediaType::IMAGE,
    ]);
    $transformer = Mockery::mock(MediaImageTransformer::class);
    $transformer->shouldReceive('process')
        ->once()
        ->andReturnUsing(function ($source, $output): array {
            file_put_contents($output, 'variation');

            return ['width' => 100, 'height' => 100, 'size' => 9];
        });
    app()->instance(MediaImageTransformer::class, $transformer);
    DB::beginTransaction();

    $variation = app(GenerateImageVariationAction::class)->execute(
        $media,
        (new ConversionDefinition('thumb'))->width(100)->height(100),
    );
    expect($variation)->toBeInstanceOf(MediaImageVariation::class);
    $path = $variation?->getPath() ?? '';
    Storage::disk('public')->assertExists($path);
    DB::rollBack();

    expect(MediaImageVariation::query()->where('media_id', $media->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing($path);
});
