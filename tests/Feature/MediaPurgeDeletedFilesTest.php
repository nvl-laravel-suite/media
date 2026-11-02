<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\DefaultMediaAuthorization;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Tests\Stubs\TestMediaUser;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    DB::connection((new Media)->getConnectionName())->commit();
    Storage::fake('public');
    config(['nvl-media.delete_files_on_media_delete' => false]);
});

/** @return array{media:Media,original:string,variation:string} */
function mediaPurgeTombstone(): array
{
    $media = Media::factory()->create(['disk' => 'public', 'is_public' => false]);
    $variation = MediaImageVariation::factory()->for($media, 'media')->create(['storage_path' => 'media/purge/'.$media->id.'-variation.jpg']);
    $original = $media->buildPath();
    Storage::disk('public')->put($original, 'original bytes');
    Storage::disk('public')->put($variation->getPath(), 'variation bytes');
    app(MediaLibrary::class)->delete(SystemActor::Background, $media);

    return ['media' => Media::withTrashed()->findOrFail($media->id), 'original' => $original, 'variation' => $variation->getPath()];
}

/** Install a real disk adapter with targeted delete failure injection. */
function mediaPurgeFailingDisk(string $path, string $failure): FilesystemAdapter
{
    $disk = Storage::disk('public');
    $failing = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        public string $failurePath;

        public string $failure;

        public function delete($paths)
        {
            if (in_array($this->failurePath, (array) $paths, true)) {
                return match ($this->failure) {
                    'throw' => throw new RuntimeException('Injected purge storage failure.'),
                    'pretend' => true,
                    default => false,
                };
            }

            return parent::delete($paths);
        }
    };
    $failing->failurePath = $path;
    $failing->failure = $failure;
    Storage::set('public', $failing);

    return $disk;
}

it('purges every retained tombstone object idempotently without rewriting history', function (): void {
    $fixture = mediaPurgeTombstone();
    $before = $fixture['media']->getAttributes();
    $library = app(MediaLibrary::class);

    expect($library->purgeDeletedFiles(SystemActor::Background, $fixture['media']))->toBeTrue();
    expect($library->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id))->toBeTrue();

    Storage::disk('public')->assertMissing([$fixture['original'], $fixture['variation']]);
    expect(Media::withTrashed()->findOrFail($fixture['media']->id)->getAttributes())->toBe($before);
    expect(MediaImageVariation::where('media_id', $fixture['media']->id)->count())->toBe(1);
});

it('returns false when object removal fails or cannot be confirmed', function (string $failure): void {
    $fixture = mediaPurgeTombstone();
    mediaPurgeFailingDisk($fixture['original'], $failure);

    expect(app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id))->toBeFalse();

    Storage::disk('public')->assertExists($fixture['original']);
    expect(Media::withTrashed()->findOrFail($fixture['media']->id)->trashed())->toBeTrue();
})->with(['delete rejected' => 'false', 'delete exception' => 'throw', 'driver falsely reports success' => 'pretend']);

it('retries remaining variation bytes after partial physical deletion', function (): void {
    $fixture = mediaPurgeTombstone();
    $disk = mediaPurgeFailingDisk($fixture['variation'], 'false');
    $library = app(MediaLibrary::class);

    expect($library->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id))->toBeFalse();
    Storage::disk('public')->assertMissing($fixture['original']);
    Storage::disk('public')->assertExists($fixture['variation']);
    Storage::set('public', $disk);

    expect($library->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id))->toBeTrue();
    Storage::disk('public')->assertMissing([$fixture['original'], $fixture['variation']]);
});

it('rechecks current delete authorization even when the bytes are already absent', function (): void {
    $fixture = mediaPurgeTombstone();
    expect(app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id))->toBeTrue();
    app()->bind(MediaAuthorization::class, DefaultMediaAuthorization::class);
    app()->forgetScopedInstances();

    expect(fn () => app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id))->toThrow(AuthorizationException::class);
});

it('refuses to purge active media or unknown identities', function (): void {
    $media = Media::factory()->create(['disk' => 'public']);
    Storage::disk('public')->put($media->buildPath(), 'active bytes');

    expect(fn () => app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, $media))->toThrow(MediaUploadException::class, 'deleted');
    expect(fn () => app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, 'missing-media'))->toThrow(ModelNotFoundException::class);
    Storage::disk('public')->assertExists($media->buildPath());
});

it('refuses an outer source transaction before deleting any object', function (): void {
    $fixture = mediaPurgeTombstone();
    $connection = $fixture['media']->getConnection();
    $connection->beginTransaction();

    try {
        expect(fn () => app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id))->toThrow(MediaUploadException::class, 'transaction');
        Storage::disk('public')->assertExists([$fixture['original'], $fixture['variation']]);
    } finally {
        $connection->rollBack();
    }
});

it('uses persisted storage facts instead of a carried model or loaded variation', function (): void {
    $fixture = mediaPurgeTombstone();
    Storage::disk('public')->put('media/keep-unrelated.txt', 'untouched');
    $fixture['media']->forceFill(['storage_path' => 'media/keep-unrelated.txt', 'folder' => 'forged', 'hash' => 'forged.jpg']);
    $fixture['media']->setRelation('imageVariations', collect());

    expect(app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, $fixture['media']))->toBeTrue();

    Storage::disk('public')->assertMissing([$fixture['original'], $fixture['variation']]);
    Storage::disk('public')->assertExists('media/keep-unrelated.txt');
});

it('requires an optional owner to share the actual media connection', function (): void {
    $fixture = mediaPurgeTombstone();
    config(['database.connections.purge-other' => config('database.connections.sqlite')]);
    $owner = new class extends Model
    {
        protected $connection = 'purge-other';
    };

    expect(fn () => app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id, $owner))->toThrow(MediaUploadException::class, 'connection');
    Storage::disk('public')->assertExists([$fixture['original'], $fixture['variation']]);
});

it('authorizes against a freshly loaded optional owner rather than carried permission attributes', function (): void {
    $fixture = mediaPurgeTombstone();
    $owner = TestMediaUser::forceCreate(['name' => 'denied', 'email' => 'purge-owner@example.test', 'password' => 'not-used']);
    $owner->name = 'allowed';
    app()->bind(MediaAuthorization::class, static fn () => new class implements MediaAuthorization
    {
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return $ability === MediaAbility::Delete && $media?->trashed() && $owner?->getAttribute('name') === 'allowed';
        }
    });
    app()->forgetScopedInstances();

    expect(fn () => app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id, $owner))->toThrow(AuthorizationException::class);
    Storage::disk('public')->assertExists([$fixture['original'], $fixture['variation']]);
    $owner->newQuery()->whereKey($owner->getKey())->update(['name' => 'allowed']);

    expect(app(MediaLibrary::class)->purgeDeletedFiles(SystemActor::Background, $fixture['media']->id, $owner))->toBeTrue();
});
