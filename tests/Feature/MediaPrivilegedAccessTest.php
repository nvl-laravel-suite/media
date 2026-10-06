<?php

declare(strict_types=1);

use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAccessService;
use Nvl\Media\Services\MediaPrivilegedAccess;
use Nvl\Media\Tests\Stubs\TestFallbackPermissionMediaUser;
use Nvl\Media\Tests\Stubs\TestPermissionMediaUser;

test('privileged access falls back to an explicitly implemented permission method', function (): void {
    config()->set('nvl-media.authorization.spatie_permission.enabled', true);
    config()->set('nvl-media.authorization.spatie_permission.global_roles', []);
    config()->set('nvl-media.authorization.spatie_permission.global_permission', '');
    config()->set(
        'nvl-media.authorization.spatie_permission.ability_permissions.delete',
        'media.delete-any',
    );

    $actor = new TestFallbackPermissionMediaUser;

    expect(app(MediaPrivilegedAccess::class)->allows($actor, MediaAbility::Delete))
        ->toBeTrue();
});

test('default bridge refuses even a namespaced cross-owner grant', function (): void {
    expect(config('nvl-media.authorization.spatie_permission.enabled'))->toBeFalse();
    $actor = (new TestPermissionMediaUser)->withMediaPermissions(['nvl.media.manage']);
    expect(app(MediaPrivilegedAccess::class)->allows($actor, MediaAbility::Delete))->toBeFalse();
});

test('explicit bridge requires namespaced grants rather than generic media permissions', function (): void {
    config(['nvl-media.authorization.spatie_permission.enabled' => true]);
    $actor = (new TestPermissionMediaUser)->withMediaPermissions(['media.manage']);
    $privateMedia = new Media(['is_public' => false, 'uploaded_by' => 'other-owner', 'uploaded_by_type' => 'other-user']);
    expect(app(MediaAccessService::class)->allows($actor, MediaAbility::Delete, $privateMedia))->toBeFalse();
    $actor->withMediaPermissions(['nvl.media.manage']);
    expect(app(MediaAccessService::class)->allows($actor, MediaAbility::Delete, $privateMedia))->toBeTrue();
});
