<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaDiskResolver;

it('prefers explicit disk over media and filesystem defaults', function (): void {
    config([
        'nvl-media.disk' => 'cloudflare-r2',
        'filesystems.default' => 'public',
    ]);

    expect(MediaDiskResolver::resolve('local'))->toBe('local');
});

it('uses media disk before the application filesystem default', function (): void {
    config([
        'nvl-media.disk' => 'cloudflare-r2',
        'filesystems.default' => 'public',
    ]);

    expect(MediaDiskResolver::resolve())->toBe('cloudflare-r2');
});

it('falls back to filesystem default when media disk is empty', function (): void {
    config([
        'nvl-media.disk' => '',
        'filesystems.default' => 'public',
    ]);

    expect(MediaDiskResolver::resolve())->toBe('public');
});

it('resolves and validates the effective media disk through the guard', function (): void {
    config([
        'nvl-media.disk' => 'cloudflare-r2',
        'nvl-media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    expect(app(MediaDiskGuard::class)->resolveAllowed())->toBe('cloudflare-r2');
});

it('resolves visibility defaults after explicit disks and before the generic default', function (): void {
    config(['nvl-media.disk' => 'local', 'nvl-media.public_disk' => 'public', 'nvl-media.private_disk' => 'vault']);

    expect(MediaDiskResolver::resolve(null, true))->toBe('public')
        ->and(MediaDiskResolver::resolve(null, false))->toBe('vault')
        ->and(MediaDiskResolver::resolve('archive', true))->toBe('archive')
        ->and(MediaDiskResolver::resolve())->toBe('local');
});

it('preserves generic fallback when visibility disks are not configured', function (): void {
    config(['nvl-media.disk' => 'archive', 'nvl-media.public_disk' => null, 'nvl-media.private_disk' => null]);

    expect(MediaDiskResolver::resolve(null, true))->toBe('archive')
        ->and(MediaDiskResolver::resolve(null, false))->toBe('archive');
});

it('keeps explicit slot disks and visibility overrides deterministic', function (): void {
    config(['nvl-media.disk' => 'local', 'nvl-media.public_disk' => 'public', 'nvl-media.private_disk' => 'vault']);

    $slot = (new MediaSlot('avatar'))->isPublic();
    expect($slot->resolveDisk())->toBe('public')
        ->and($slot->resolveDisk(false))->toBe('vault')
        ->and($slot->useDisk('local')->resolveDisk())->toBe('local');
});

it('does not silently allowlist a visibility disk', function (): void {
    config(['nvl-media.private_disk' => 'vault', 'nvl-media.allowed_disks' => ['public']]);

    expect(fn () => app(MediaDiskGuard::class)->resolveAllowed(null, false))
        ->toThrow(ValidationException::class);
});
