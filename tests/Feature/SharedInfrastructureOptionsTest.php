<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Nvl\Media\Services\MediaMultipartLock;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Support\MediaQueueConfiguration;

it('inherits shared queue names in media jobs', function (): void {
    config(['nvl-media.queue.name' => null, 'nvl-core.queue.name' => 'shared-media-work']);
    expect(MediaQueueConfiguration::name())->toBe('shared-media-work');
});

it('uses the inherited Core store for media mutation and multipart locks', function (): void {
    config([
        'nvl-media.mutation_lock.store' => null,
        'nvl-media.multipart.lock.store' => null,
        'nvl-media.locks' => ['store' => null, 'mutation' => ['store' => null], 'multipart' => ['store' => null]],
        'nvl-core.locks.store' => 'shared-locks',
        'cache.stores.shared-locks' => ['driver' => 'array'],
    ]);
    $repository = Cache::store('shared-locks');
    Cache::shouldReceive('store')->with('shared-locks')->twice()->andReturn($repository);

    expect(app(MediaMutationLock::class)->execute('media-id', fn (): string => 'mutation'))->toBe('mutation')
        ->and(app(MediaMultipartLock::class)->execute('session-id', fn (): string => 'multipart'))->toBe('multipart');
});
