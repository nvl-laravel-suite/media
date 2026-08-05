<?php

declare(strict_types=1);

use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Services\MediaPrivilegedAccess;
use Nvl\Media\Tests\Stubs\TestFallbackPermissionMediaUser;

test('privileged access falls back to an explicitly implemented permission method', function (): void {
    config()->set('media.authorization.spatie_permission.global_roles', []);
    config()->set('media.authorization.spatie_permission.global_permission', '');
    config()->set(
        'media.authorization.spatie_permission.ability_permissions.delete',
        'media.delete-any',
    );

    $actor = new TestFallbackPermissionMediaUser;

    expect(app(MediaPrivilegedAccess::class)->allows($actor, MediaAbility::Delete))
        ->toBeTrue();
});
