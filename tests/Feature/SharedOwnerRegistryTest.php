<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Nvl\Media\Services\MediaAssociableResolver;
use Nvl\Media\Tests\Stubs\TestMediaUser;

beforeEach(function (): void {
    $this->originalOwnerMorphMap = Relation::morphMap();
    config()->set('nvl-core.owners', ['person' => TestMediaUser::class]);
    config()->set('nvl-media.allowed_associable_types', ['person']);
    config()->set('nvl-media.associable_mutation_abilities', ['person' => 'attach-media']);
});

afterEach(function (): void {
    Relation::morphMap($this->originalOwnerMorphMap, false);
});

it('resolves allowlisted shared aliases and preserves per-owner mutation abilities', function (): void {
    $owner = TestMediaUser::forceCreate(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password']);
    $this->actingAs($owner);
    $registry = app(MediaAssociableResolver::class);
    Gate::define('attach-media', static fn (): bool => false);

    expect(fn () => $registry->resolveForMutation('person', (string) $owner->getKey()))
        ->toThrow(AuthorizationException::class);

    Gate::define('attach-media', static fn (): bool => true);

    expect($registry->resolveForMutation('person', (string) $owner->getKey())->is($owner))->toBeTrue();
});

it('rejects a registered shared alias when media has not allowlisted it', function (): void {
    config()->set('nvl-media.allowed_associable_types', []);

    expect(fn () => app(MediaAssociableResolver::class)->resolveForMutation('person', '1'))
        ->toThrow(AuthorizationException::class);
});

it('prefers the class-keyed mutation ability over a resolved historical capability key', function (): void {
    config()->set('nvl-media.associable_mutation_abilities', [
        'person' => 'legacy-attach',
        TestMediaUser::class => 'class-attach',
    ]);
    $owner = TestMediaUser::forceCreate(['name' => 'Owner', 'email' => 'class-owner@example.test', 'password' => 'password']);
    $this->actingAs($owner);
    Gate::define('legacy-attach', static fn (): bool => false);
    Gate::define('class-attach', static fn (): bool => true);

    expect(app(MediaAssociableResolver::class)->resolveForMutation('person', (string) $owner->getKey())->is($owner))->toBeTrue();
});
