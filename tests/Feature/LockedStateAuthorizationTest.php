<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Events\MediaAttached;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaScan;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Services\MediaVariations;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Support\Enums\SystemActor;

/**
 * Grant every ability unless the subject the policy observes carries a denied marker.
 */
function useLockedStateAuthorization(): void
{
    app()->instance(MediaAuthorization::class, new class implements MediaAuthorization
    {
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return $media?->filename !== 'denied.txt' && $owner?->getAttribute('name') !== 'Denied';
        }
    });
    app()->forgetScopedInstances();
}

/**
 * Persist a policy-relevant change inside the operation's nth transaction, so a denial also rolls it back.
 *
 * @param  Closure(): void  $change
 */
function changeAtTransactionEntry(Closure $change, int $entry = 1): void
{
    $entries = 0;
    Event::listen(TransactionBeginning::class, static function () use (&$entries, $change, $entry): void {
        if (++$entries === $entry) {
            $change();
        }
    });
}

/**
 * @param  list<string>  $mediaIds
 */
function denyMediaAtTransactionEntry(array $mediaIds, int $entry = 1): void
{
    changeAtTransactionEntry(static function () use ($mediaIds): void {
        Media::query()->whereIn('id', $mediaIds)->update(['filename' => 'denied.txt']);
    }, $entry);
}

function lockedStateStoredMedia(string $contents = 'locked state bytes', array $attributes = []): Media
{
    $media = Media::factory()->create([
        'filename' => 'allowed.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'size' => strlen($contents),
        'disk' => 'public', 'is_public' => true, 'folder' => 'source', 'digest' => hash('sha256', $contents), 'tags' => ['kept'],
        ...$attributes,
    ]);
    Storage::disk('public')->put($media->buildPath(), $contents);

    return $media;
}

beforeEach(function (): void {
    config([
        'filesystems.disks.relocated' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/relocated')],
        'nvl-media.allowed_disks' => ['public', 'relocated'],
        'nvl-media.auto_generate_variations' => false,
    ]);
    Storage::fake('public');
    Storage::fake('relocated');
    useLockedStateAuthorization();
});

it('authorizes the locked asset state for every single-asset mutation', function (Closure $mutate): void {
    $media = lockedStateStoredMedia();
    $path = $media->buildPath();
    denyMediaAtTransactionEntry([$media->id]);

    expect(fn () => $mutate($media))->toThrow(AuthorizationException::class);

    $current = Media::query()->findOrFail($media->id);
    expect($current->filename)->toBe('allowed.txt')
        ->and($current->disk)->toBe('public')
        ->and($current->folder)->toBe('source')
        ->and($current->revision)->toBe($media->revision)
        ->and($current->tags)->toBe(['kept'])
        ->and($current->metadata)->toBe($media->metadata)
        ->and(Storage::disk('public')->allFiles())->toBe([$path])
        ->and(Storage::disk('relocated')->allFiles())->toBe([]);
})->with([
    'rename' => [static fn (Media $media): Media => app(MediaLibrary::class)->rename(SystemActor::Background, $media, 'renamed.txt')],
    'metadata' => [static fn (Media $media): Media => app(MediaLibrary::class)->updateMetadata(SystemActor::Background, $media, ['tags' => ['changed'], 'metadata' => ['changed' => true]])],
    'relocation' => [static fn (Media $media): Media => app(MediaLibrary::class)->relocate(SystemActor::Background, $media, 'relocated', MediaVisibility::Public)],
    'tag' => [static fn (Media $media): Media => app(MediaLibrary::class)->tag(SystemActor::Background, $media, ['changed'], ['kept'])],
    'replacement' => [static fn (Media $media): Media => app(MediaUploader::class)->replace(SystemActor::Background, $media, UploadedFile::fake()->createWithContent('replacement.txt', 'replacement bytes'))],
]);

it('authorizes the locked asset state before applying a scan attestation', function (): void {
    $media = lockedStateStoredMedia(attributes: ['status' => MediaLifecycleStatus::PendingScan]);
    denyMediaAtTransactionEntry([$media->id]);

    expect(fn () => app(MediaScan::class)->finalize(SystemActor::Background, $media, ['clean' => false, 'mimeType' => 'text/plain', 'extension' => 'txt', 'size' => 18, 'checksum' => hash('sha256', 'locked state bytes'), 'diagnostics' => []]))
        ->toThrow(AuthorizationException::class);

    expect(Media::query()->findOrFail($media->id)->status)->toBe(MediaLifecycleStatus::PendingScan);
});

it('authorizes every locked member before a bulk mutation writes any of them', function (string $operation): void {
    $first = lockedStateStoredMedia('first bytes');
    $second = lockedStateStoredMedia('second bytes');
    $paths = [$first->buildPath(), $second->buildPath()];
    sort($paths);
    denyMediaAtTransactionEntry([$second->id]);

    expect(fn () => match ($operation) {
        'move' => app(MediaLibrary::class)->move(SystemActor::Background, [$first->id, $second->id], 'moved'),
        'bulk tag' => app(MediaLibrary::class)->bulkTag(SystemActor::Background, [$first->id, $second->id], ['changed']),
        'bulk delete' => app(MediaLibrary::class)->bulkDelete(SystemActor::Background, [$first->id, $second->id]),
    })->toThrow(AuthorizationException::class);

    foreach ([$first, $second] as $media) {
        $current = Media::withTrashed()->findOrFail($media->id);
        expect($current->trashed())->toBeFalse()->and($current->folder)->toBe('source')->and($current->tags)->toBe(['kept']);
    }
    $files = Storage::disk('public')->allFiles();
    sort($files);
    expect($files)->toBe($paths);
})->with(['move', 'bulk tag', 'bulk delete']);

it('authorizes the locked canonical owner before attaching', function (): void {
    $media = lockedStateStoredMedia();
    $owner = TestMediaModel::query()->create(['name' => 'Allowed']);
    changeAtTransactionEntry(static function () use ($owner): void {
        TestMediaModel::query()->whereKey($owner->getKey())->update(['name' => 'Denied']);
    });
    Event::fake([MediaAttached::class]);

    expect(fn () => app(MediaAttachments::class)->attach(SystemActor::Background, $media, $owner, 'gallery', dispatchVariations: false))
        ->toThrow(AuthorizationException::class);

    expect(MediaAssociation::query()->where('media_id', $media->id)->exists())->toBeFalse();
    Event::assertNotDispatched(MediaAttached::class);
});

it('authorizes the locked canonical asset before attaching', function (): void {
    $media = lockedStateStoredMedia();
    $owner = TestMediaModel::query()->create(['name' => 'Allowed']);
    denyMediaAtTransactionEntry([$media->id]);
    Event::fake([MediaAttached::class]);

    expect(fn () => app(MediaAttachments::class)->attach(SystemActor::Background, $media, $owner, 'gallery', dispatchVariations: false))
        ->toThrow(AuthorizationException::class);

    expect(MediaAssociation::query()->where('media_id', $media->id)->exists())->toBeFalse();
    Event::assertNotDispatched(MediaAttached::class);
});

it('authorizes the locked asset state before and when committing a variation', function (int $entry): void {
    $source = UploadedFile::fake()->image('source.png', 32, 32)->getContent();
    $media = lockedStateStoredMedia($source, ['extension' => 'png', 'mime_type' => 'image/png', 'type' => MediaType::IMAGE, 'status' => MediaLifecycleStatus::Available]);
    $path = $media->buildPath();
    denyMediaAtTransactionEntry([$media->id], $entry);

    expect(fn () => app(MediaVariations::class)->generate(SystemActor::Background, $media, ConversionDefinition::fromPreset('thumb', ['width' => 16, 'height' => 16, 'format' => 'png'])))
        ->toThrow(AuthorizationException::class);

    expect(MediaImageVariation::query()->where('media_id', $media->id)->exists())->toBeFalse()
        ->and(Media::query()->findOrFail($media->id)->status)->toBe(MediaLifecycleStatus::Available)
        ->and(Storage::disk('public')->allFiles())->toBe([$path]);
})->with(['before the processing write' => 1, 'at the variation commit' => 2]);
