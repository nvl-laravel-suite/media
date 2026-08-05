<?php

declare(strict_types=1);

use Nvl\Media\Providers\MediaServiceProvider;

test('partial consumer config preserves nested media defaults', function (): void {
    config()->set('media', [
        'assets' => [
            'public_cache_control' => 'public, max-age=3600',
        ],
    ]);

    (new MediaServiceProvider(app()))->register();

    expect(config('media.assets.public_cache_control'))->toBe('public, max-age=3600')
        ->and(config('media.assets.private_cache_control'))->toBe('private, max-age=0, no-store')
        ->and(config('media.assets.allowed_parameters'))->toBe(['v']);
});
