<?php

declare(strict_types=1);
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Events\MediaAttached;
use Nvl\Media\Events\MediaDetached;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Events\MediaUploaded;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaAccessService;
use Nvl\Media\Services\MediaPrivilegedAccess;
use Nvl\Media\Support\MediaActors;
use Nvl\Media\Support\MediaAssociationSnapshot;
use Nvl\Media\Tests\Stubs\TestMediaUser;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Enums\SystemActor;

test('media public mutation events dispatch after commit', function (): void {
    expect(is_subclass_of(MediaAttached::class, DomainEvent::class))->toBeTrue()->and(is_subclass_of(MediaDetached::class, DomainEvent::class))->toBeTrue()->and(is_subclass_of(MediaMutated::class, DomainEvent::class))->toBeTrue()->and(is_subclass_of(MediaUploaded::class, DomainEvent::class))->toBeTrue();
});
test('media association snapshots expose stable generic owner metadata', function (): void {
    $association = new MediaAssociation(['media_id' => '00000000-0000-0000-0000-000000000001', 'associable_type' => 'module.model', 'associable_id' => '00000000-0000-0000-0000-000000000002', 'collection' => 'image', 'locale' => 'bg']);
    expect(MediaAssociationSnapshot::fromAssociation($association))->toBe(['media_id' => '00000000-0000-0000-0000-000000000001', 'associable_type' => 'module.model', 'associable_id' => '00000000-0000-0000-0000-000000000002', 'collection' => 'image', 'locale' => 'bg']);
});

test('media actors reject caller-mutated persistence identities', function (string $mutation): void {
    $actor = TestMediaUser::query()->create(['name' => 'Canonical actor', 'email' => 'actor@example.test', 'password' => 'password']);
    if ($mutation === 'table') {
        $actor->setTable('other_actors');
    } elseif ($mutation === 'key') {
        $actor->setAttribute($actor->getKeyName(), 'forged-identity');
    } else {
        $actor->setKeyName('name');
    }

    MediaActors::assert($actor);
})->with(['table', 'key', 'key-name'])->throws(AuthorizationException::class);

test('media authorization reloads actor attributes instead of trusting caller changes', function (): void {
    $actor = TestMediaUser::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'password']);
    $actor->name = 'Administrator';
    $policy = new class implements MediaAuthorization
    {
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return $actor instanceof TestMediaUser && $actor->name === 'Administrator';
        }
    };
    $access = new MediaAccessService($policy, new MediaPrivilegedAccess);

    expect($access->allows($actor, MediaAbility::ListAll))->toBeFalse();
    $actor->newQuery()->whereKey($actor->getKey())->update(['name' => 'Administrator']);
    $actor->name = 'Member';
    expect($access->allows($actor, MediaAbility::ListAll))->toBeTrue();
});
