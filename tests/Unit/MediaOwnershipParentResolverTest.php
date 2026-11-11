<?php

declare(strict_types=1);
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Media\Ownership\MediaOwnershipParentResolver;
use Nvl\Media\Tests\Fixtures\CountedIdentityModel;
use Nvl\Media\Tests\Fixtures\CustomMorphIdentityModel;
use Nvl\Media\Tests\Fixtures\FirstIdentityModel;
use Nvl\Media\Tests\Fixtures\SecondIdentityModel;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

function identityResolver(): MediaOwnershipParentResolver
{
    $registry = new OwnershipResourceRegistry;
    foreach (['first' => FirstIdentityModel::class, 'custom' => CustomMorphIdentityModel::class, 'second' => SecondIdentityModel::class] as $key => $model) {
        $registry->register(new OwnershipResourceDefinition("fixture.{$key}", 'fixture', $model, OwnershipDefinition::unscoped()));
    }
    CountedIdentityModel::$constructed = [];

    return new MediaOwnershipParentResolver($registry, app(OwnershipBoundary::class), app(OwnershipContext::class), app(OwnerRegistry::class));
}

afterEach(function (): void {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

it('resolves a registered class identity without constructing unrelated resource models', function (): void {
    expect(identityResolver()->classForIdentity(SecondIdentityModel::class))->toBe(SecondIdentityModel::class)
        ->and(array_keys(CountedIdentityModel::$constructed))->toBe([CustomMorphIdentityModel::class, SecondIdentityModel::class]);
});

it('resolves a morph-map alias without constructing unrelated resource models', function (): void {
    Relation::morphMap(['second-alias' => SecondIdentityModel::class]);
    $resolver = identityResolver();

    expect($resolver->classForIdentity('second-alias'))->toBe(SecondIdentityModel::class)
        ->and(CountedIdentityModel::$constructed)->not->toHaveKey(FirstIdentityModel::class);
    expect(fn () => $resolver->classForIdentity(SecondIdentityModel::class))->toThrow(InvalidArgumentException::class, 'not declared');
});

it('still resolves a resource that customises its morph class', function (): void {
    expect(identityResolver()->classForIdentity('custom-identity'))->toBe(CustomMorphIdentityModel::class)
        ->and(array_keys(CountedIdentityModel::$constructed))->toBe([CustomMorphIdentityModel::class]);
});

it('falls through to declared owner identities without constructing unrelated resource models', function (): void {
    expect(fn () => identityResolver()->classForIdentity('undeclared-owner'))->toThrow(InvalidArgumentException::class, 'not declared')
        ->and(array_keys(CountedIdentityModel::$constructed))->toBe([CustomMorphIdentityModel::class]);
});

it('keeps the morph-map violation for an unmapped resource under an enforced morph map', function (): void {
    Relation::enforceMorphMap(['second-alias' => SecondIdentityModel::class]);

    expect(fn () => identityResolver()->classForIdentity('second-alias'))->toThrow(ClassMorphViolationException::class);
});
