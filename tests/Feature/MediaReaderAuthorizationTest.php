<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Media\Tests\Stubs\TestMediaUser;
use Nvl\Support\Enums\SystemActor;

/** Bind an explicit host policy without granting background actors implicit authority. */
function mediaReaderAuthorization(Closure $allows): void
{
    app()->instance(MediaAuthorization::class, new class($allows) implements MediaAuthorization
    {
        public function __construct(private Closure $allows) {}

        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return ($this->allows)($ability, $media, $owner);
        }
    });
    app()->forgetScopedInstances();
}

it('requires List before exposing public filter metadata', function (): void {
    Media::factory()->create(['is_public' => true, 'folder' => 'restricted-campaign']);
    mediaReaderAuthorization(static fn (MediaAbility $ability): bool => $ability !== MediaAbility::List);
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(fn () => app(MediaReader::class)->filterOptions(SystemActor::Background))
        ->toThrow(AuthorizationException::class);
    expect(DB::getQueryLog())->toBe([]);
});

it('fails the whole management page when ListAll is granted but one asset View is denied', function (): void {
    Media::factory()->create(['filename' => 'allowed.txt', 'is_public' => false]);
    $denied = Media::factory()->create(['filename' => 'denied.txt', 'is_public' => false]);
    mediaReaderAuthorization(static fn (MediaAbility $ability, ?Media $media): bool => $ability !== MediaAbility::View || $media?->id !== $denied->id);

    expect(fn () => app(MediaReader::class)->paginate(SystemActor::Background, ['sortBy' => 'filename', 'sortDirection' => 'asc']))
        ->toThrow(AuthorizationException::class);
});

it('omits inactive owner associations before applying the slot limit', function (): void {
    $owner = TestMediaModel::query()->create(['name' => 'Owner']);
    $inactive = Media::factory()->create(['is_public' => true]);
    $active = Media::factory()->create(['is_public' => true]);
    MediaAssociation::factory()->forMedia($inactive)->forOwner($owner)->create(['collection' => 'gallery', 'is_active' => false, 'order' => 0]);
    MediaAssociation::factory()->forMedia($active)->forOwner($owner)->create(['collection' => 'gallery', 'is_active' => true, 'order' => 1]);

    $result = app(MediaReader::class)->slots(SystemActor::Background, $owner, ['gallery'], 1);

    expect(array_map(static fn (Media $asset): string => $asset->id, $result['gallery']))->toBe([$active->id]);
});

it('omits unavailable owner assets before applying the slot limit', function (MediaLifecycleStatus $status): void {
    $owner = TestMediaModel::query()->create(['name' => 'Owner']);
    $unavailable = Media::factory()->create(['is_public' => true, 'status' => $status]);
    $available = Media::factory()->create(['is_public' => true]);
    MediaAssociation::factory()->forMedia($unavailable)->forOwner($owner)->create(['collection' => 'gallery', 'order' => 0]);
    MediaAssociation::factory()->forMedia($available)->forOwner($owner)->create(['collection' => 'gallery', 'order' => 1]);

    $result = app(MediaReader::class)->slots(SystemActor::Background, $owner, ['gallery'], 1);

    expect(array_map(static fn (Media $asset): string => $asset->id, $result['gallery']))->toBe([$available->id]);
})->with([
    MediaLifecycleStatus::PendingUpload,
    MediaLifecycleStatus::PendingScan,
    MediaLifecycleStatus::Quarantined,
    MediaLifecycleStatus::Failed,
    MediaLifecycleStatus::Deleted,
]);

it('authorizes a private slot asset once with the canonical owner', function (): void {
    $owner = TestMediaModel::query()->create(['name' => 'Canonical owner']);
    $asset = Media::factory()->create(['is_public' => false]);
    MediaAssociation::factory()->forMedia($asset)->forOwner($owner)->create(['collection' => 'gallery']);
    $owner->name = 'Forged owner';
    $views = 0;
    mediaReaderAuthorization(static function (MediaAbility $ability, ?Media $media, ?Model $canonical) use ($owner, $asset, &$views): bool {
        if ($ability !== MediaAbility::View) {
            return false;
        }
        $views++;

        return $media?->id === $asset->id && $canonical?->is($owner) === true && $canonical->getAttribute('name') === 'Canonical owner';
    });

    $result = app(MediaReader::class)->slots(SystemActor::Background, $owner, ['gallery']);

    expect($result['gallery'])->toHaveCount(1)
        ->and($result['gallery'][0]->id)->toBe($asset->id)
        ->and($views)->toBe(1);
});

it('requires an owner-aware View grant even when unscoped asset View is granted', function (): void {
    $owner = TestMediaModel::query()->create(['name' => 'Owner']);
    $asset = Media::factory()->create(['is_public' => true]);
    MediaAssociation::factory()->forMedia($asset)->forOwner($owner)->create(['collection' => 'gallery']);
    mediaReaderAuthorization(static fn (MediaAbility $ability, ?Media $media, ?Model $canonical): bool => $canonical === null);

    expect(fn () => app(MediaReader::class)->slots(SystemActor::Background, $owner, ['gallery']))
        ->toThrow(AuthorizationException::class);
});

it('hides other uploaders\' public assets that are not available from a non-manager library', function (): void {
    $actor = TestMediaUser::query()->create(['name' => 'Reader', 'email' => 'reader@library.test', 'password' => 'unused']);
    $available = Media::factory()->create(['is_public' => true, 'filename' => 'available.txt']);
    $processing = Media::factory()->create(['is_public' => true, 'filename' => 'processing.txt', 'status' => MediaLifecycleStatus::ProcessingVariations]);
    $pending = Media::factory()->create(['is_public' => true, 'filename' => 'pending.txt', 'status' => MediaLifecycleStatus::PendingScan]);
    $quarantined = Media::factory()->create(['is_public' => true, 'filename' => 'quarantined.txt', 'status' => MediaLifecycleStatus::Quarantined]);
    $ownPending = Media::factory()->create(['is_public' => true, 'filename' => 'own-pending.txt', 'status' => MediaLifecycleStatus::PendingScan, 'uploaded_by' => (string) $actor->getKey(), 'uploaded_by_type' => $actor->getMorphClass()]);
    mediaReaderAuthorization(static fn (MediaAbility $ability): bool => $ability !== MediaAbility::ListAll);

    $listed = app(MediaReader::class)->paginate($actor)->pluck('id')->sort()->values()->all();

    expect($listed)->toBe(collect([$available->id, $processing->id, $ownPending->id])->sort()->values()->all());
    expect(fn () => app(MediaReader::class)->get($actor, $pending->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(MediaReader::class)->get($actor, $quarantined->id))->toThrow(ModelNotFoundException::class);

    mediaReaderAuthorization(static fn (): bool => true);
    expect(app(MediaReader::class)->paginate($actor)->total())->toBe(5);
});
