<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\MediaOwner;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAssociableResolver;
use Nvl\Media\Services\MediaOwnerSlotResolver;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Media\Tests\Stubs\TestMediaUser;
use Nvl\Media\Traits\InteractsWithNvlMedia;

/** Media owner exposing only the namespaced capability to host models. */
final class NamespacedMediaOwner extends Model implements HasNvlMedia
{
    use HasUuids;
    use InteractsWithNvlMedia;

    protected $table = 'test_media_models';

    protected $fillable = ['name'];

    /** Register the independent NVL slot and its fallback. */
    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        $media->slot('portrait')->singleFile()->useFallbackUrl('/portrait.svg');
        $media->slot('documents');
    }
}

it('owns uploads and mutations through the natural namespaced API', function (): void {
    Storage::fake('public');
    $owner = NamespacedMediaOwner::query()->create(['name' => 'Namespaced owner']);
    $api = $owner->nvlMedia();

    expect($api)->toBeInstanceOf(MediaOwner::class)
        ->and($owner->nvlMedia())->toBe($api)
        ->and($owner->nvlMediaAssociations())->toBeInstanceOf(MorphMany::class)
        ->and($api->firstUrl('portrait'))->toBe('/portrait.svg');

    $first = $api->add(UploadedFile::fake()->createWithContent('first.txt', 'first file'))
        ->toCollection('documents')->upload();
    $second = $api->fromString('second file')->usingFileName('second.txt')->toCollection('documents')->upload();
    expect($api->all('documents')->pluck('id')->all())->toBe([$first->id, $second->id]);

    $api->detach($first, 'documents');
    expect($api->first('documents')?->id)->toBe($second->id)
        ->and(Media::query()->find($first->id))->not->toBeNull();
    $api->attach($first, 'documents');
    $api->sync([$first], 'documents');
    expect($api->all('documents')->pluck('id')->all())->toBe([$first->id])
        ->and(Media::query()->find($second->id))->not->toBeNull();
});

it('reads eager NVL associations without loading a foreign media relation', function (): void {
    Storage::fake('public');
    $owner = NamespacedMediaOwner::query()->create(['name' => 'Eager owner']);
    $asset = $owner->nvlMedia()->fromString('eager')->usingFileName('eager.txt')->toCollection('documents')->upload();
    $loaded = NamespacedMediaOwner::query()->with('nvlMediaAssociations.media')->findOrFail($owner->id);
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($loaded->nvlMedia()->first('documents')?->id)->toBe($asset->id)
        ->and(DB::getQueryLog())->toBe([]);
});

it('retains the deprecated model surface on the same owner API', function (): void {
    Storage::fake('public');
    $owner = TestMediaModel::query()->create(['name' => 'Legacy owner']);
    $asset = $owner->addMediaFromString('legacy')->usingFileName('legacy.txt')->toCollection('default')->upload();

    expect($owner->nvlMedia()->first()?->id)->toBe($asset->id)
        ->and($owner->getFirstMedia()?->id)->toBe($asset->id);
    $owner->nvlMedia()->clear();
    expect($owner->hasMedia())->toBeFalse();
});

it('adds only the approved namespaced model entry points', function (): void {
    $methods = array_map(static fn (ReflectionMethod $method): string => $method->name,
        (new ReflectionClass(InteractsWithNvlMedia::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    sort($methods);
    expect($methods)->toBe(['bootInteractsWithNvlMedia', 'nvlMedia', 'nvlMediaAssociations', 'registerNvlMedia']);
});

it('routes internal mutation and single-slot boundaries through the new capability', function (): void {
    Storage::fake('public');
    $owner = NamespacedMediaOwner::query()->create(['name' => 'Capability only']);
    $actor = TestMediaUser::query()->create(['name' => 'Actor', 'email' => 'actor@example.test', 'password' => 'secret']);
    $this->actingAs($actor);
    config()->set('nvl-media.allowed_associable_types', [NamespacedMediaOwner::class]);
    Gate::define('update', static fn (): bool => true);

    $resolved = app(MediaAssociableResolver::class)->resolveForMutation(NamespacedMediaOwner::class, $owner->id);
    $asset = app(MediaLibraryContract::class)->fromString($resolved, 'contract input')
        ->usingFileName('contract.txt')->slot('portrait');

    expect($resolved->nvlMedia()->first('portrait')?->id)->toBe($asset->id)
        ->and(app(MediaOwnerSlotResolver::class)->slot($resolved, 'portrait')->isSingleFile)->toBeTrue()
        ->and(app(MediaOwnerSlotResolver::class)->currentAssociation($resolved, 'portrait')?->media_id)->toBe($asset->id);
});

it('invalidates eager NVL reads after attach detach and uploads', function (): void {
    Storage::fake('public');
    $owner = NamespacedMediaOwner::query()->create(['name' => 'Mutated eager reads']);
    $first = $owner->nvlMedia()->fromString('first')->usingFileName('first.txt')->slot('portrait');
    $owner->load('nvlMediaAssociations');
    $second = $owner->nvlMedia()->fromString('second')->usingFileName('second.txt')->slot('portrait');

    expect($owner->nvlMedia()->first('portrait')?->id)->toBe($second->id)
        ->and(Media::query()->find($first->id))->toBeNull();
    $owner->load('nvlMediaAssociations');
    $owner->nvlMedia()->detach($second, 'portrait');
    expect($owner->nvlMedia()->has('portrait'))->toBeFalse();
    $owner->load('nvlMediaAssociations');
    $owner->nvlMedia()->attach($second, 'portrait');
    expect($owner->nvlMedia()->has('portrait'))->toBeTrue();
});

it('binds a cloned owner API to the cloned model instance', function (): void {
    $owner = NamespacedMediaOwner::query()->create(['name' => 'Original owner']);
    $originalApi = $owner->nvlMedia();
    $clone = clone $owner;

    expect($clone->nvlMedia())->not->toBe($originalApi)
        ->and($clone->nvlMedia()->owns($clone))->toBeTrue()
        ->and($clone->nvlMedia()->owns($owner))->toBeFalse();
});

it('retains existing associations when a sync target cannot be attached', function (): void {
    Storage::fake('public');
    $owner = NamespacedMediaOwner::query()->create(['name' => 'Atomic sync']);
    $first = $owner->nvlMedia()->fromString('first')->usingFileName('first.txt')->slot('documents');
    $blocked = $owner->nvlMedia()->fromString('blocked')->usingFileName('blocked.txt')->slot('staged');
    $blocked->forceFill(['status' => MediaLifecycleStatus::PendingScan])->save();

    expect(fn () => $owner->nvlMedia()->sync([$blocked], 'documents'))->toThrow(MediaUploadException::class)
        ->and($owner->nvlMedia()->first('documents')?->id)->toBe($first->id);
});

it('reorders media for a capability-only owner through the HTTP boundary', function (): void {
    Storage::fake('public');
    $owner = NamespacedMediaOwner::query()->create(['name' => 'HTTP order']);
    $actor = TestMediaUser::query()->create(['name' => 'Actor', 'email' => 'reorder@example.test', 'password' => 'secret']);
    $this->actingAs($actor);
    config()->set('nvl-media.allowed_associable_types', [NamespacedMediaOwner::class]);
    Gate::before(static fn (): bool => true);
    $first = $owner->nvlMedia()->fromString('first')->usingFileName('first.txt')->slot('documents');
    $second = $owner->nvlMedia()->fromString('second')->usingFileName('second.txt')->slot('documents');

    $this->postJson(route('nvl.media.management.reorder'), [
        'mediaIds' => [$second->id, $first->id],
        'associableType' => NamespacedMediaOwner::class,
        'associableId' => $owner->id,
        'collection' => 'documents',
    ])->assertSuccessful();
    expect($owner->nvlMedia()->all('documents')->pluck('id')->all())->toBe([$second->id, $first->id]);
});
