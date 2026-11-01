<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Fixtures\StandaloneMediaOwner;
use Nvl\Media\Tests\StandaloneMediaTestCase;
use Nvl\Support\Enums\SystemActor;

uses(StandaloneMediaTestCase::class);

it('uploads reads and deletes private Media with only required package providers', function (): void {
    Storage::fake('local');
    $owner = StandaloneMediaOwner::query()->create(['name' => 'Lean upload owner']);
    $media = app(MediaUploader::class)->upload(
        SystemActor::Background,
        UploadedFile::fake()->createWithContent('proof.txt', 'Required dependencies only.'),
        'local', $owner, new MediaSlot('proof'), 'proof.txt',
        deduplicateExisting: false, skipAutoVariations: true,
    );
    $path = $media->buildPath();

    expect(app()->getLoadedProviders())->not->toHaveKeys(['Nvl\\Filterable\\Providers\\FilterableServiceProvider', 'Nvl\\Ownership\\Providers\\OwnershipServiceProvider']);
    expect(app(MediaReader::class)->get(SystemActor::Background, $media->id)->id)->toBe($media->id)
        ->and(Storage::disk('local')->get($path))->toBe('Required dependencies only.')
        ->and($media->is_public)->toBeFalse();
    expect(app(MediaLibrary::class)->delete(SystemActor::Background, $media))->toBeTrue();
    Storage::disk('local')->assertMissing($path);
});
