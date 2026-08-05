<?php

declare(strict_types=1);

use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Support\MediaDiskResolver;

it('prefers explicit disk over media and filesystem defaults', function (): void {
    config([
        'media.disk' => 'cloudflare-r2',
        'filesystems.default' => 'public',
    ]);

    expect(MediaDiskResolver::resolve('local'))->toBe('local');
});

it('uses media disk before the application filesystem default', function (): void {
    config([
        'media.disk' => 'cloudflare-r2',
        'filesystems.default' => 'public',
    ]);

    expect(MediaDiskResolver::resolve())->toBe('cloudflare-r2');
});

it('falls back to filesystem default when media disk is empty', function (): void {
    config([
        'media.disk' => '',
        'filesystems.default' => 'public',
    ]);

    expect(MediaDiskResolver::resolve())->toBe('public');
});

it('resolves and validates the effective media disk through the guard', function (): void {
    config([
        'media.disk' => 'cloudflare-r2',
        'media.allowed_disks' => ['public', 'cloudflare-r2'],
    ]);

    expect(app(MediaDiskGuard::class)->resolveAllowed())->toBe('cloudflare-r2');
});
