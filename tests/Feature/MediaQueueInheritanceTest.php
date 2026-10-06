<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Jobs\ProcessMediaVariationsJob;
use Nvl\Media\Support\MediaQueueConfiguration;

it('resolves Media jobs from explicit package, Core, and effective application queue settings', function (?string $media, ?string $core, string $application, string $expected): void {
    config([
        'media.queue.enabled' => true,
        'media.queue.connection' => $media,
        'nvl-core.queue.connection' => $core,
        'queue.default' => $application,
    ]);

    expect(MediaQueueConfiguration::connection())->toBe($expected)
        ->and(MediaQueueConfiguration::enabled())->toBe($expected !== 'sync')
        ->and((new GenerateImageVariationJob('media-id', 'thumb', ['width' => 80]))->connection)->toBe($expected)
        ->and((new ProcessMediaVariationsJob('media-id'))->connection)->toBe($expected);
})->with([
    'effective application queue' => [null, null, 'redis', 'redis'],
    'Core queue' => [null, 'database', 'redis', 'database'],
    'explicit Media queue' => ['sqs', 'database', 'redis', 'sqs'],
    'explicit Media sync' => ['sync', 'database', 'redis', 'sync'],
    'intentional application sync' => [null, null, 'sync', 'sync'],
]);

it('preserves queue inheritance after a PHP configuration cache round trip', function (): void {
    config([
        'media.queue.connection' => null,
        'nvl-core.queue.connection' => null,
        'queue.default' => 'runtime-queue',
    ]);
    $path = tempnam(sys_get_temp_dir(), 'media-config-');

    try {
        file_put_contents($path, '<?php return '.var_export(config()->all(), true).';');
        $this->app->instance('config', new Repository(require $path));

        expect(MediaQueueConfiguration::connection())->toBe('runtime-queue');
    } finally {
        unlink($path);
    }
});

it('keeps the Media environment override as a configuration input', function (): void {
    $previous = getenv('MEDIA_QUEUE_CONNECTION');
    putenv('MEDIA_QUEUE_CONNECTION=media-env');

    try {
        $defaults = require dirname(__DIR__, 2).'/config/media.php';
        expect($defaults['queue']['connection'])->toBe('media-env');
    } finally {
        putenv($previous === false ? 'MEDIA_QUEUE_CONNECTION' : 'MEDIA_QUEUE_CONNECTION='.$previous);
    }
});

it('rejects an empty queue override instead of silently selecting sync', function (): void {
    config(['media.queue.connection' => '', 'queue.default' => 'redis']);

    expect(fn (): string => MediaQueueConfiguration::connection())->toThrow(InvalidArgumentException::class, 'media.queue.connection');
});

it('leaves the shipped queue name nullable for Core and application inheritance', function (): void {
    $previous = getenv('MEDIA_QUEUE');
    putenv('MEDIA_QUEUE');

    try {
        $defaults = require dirname(__DIR__, 2).'/config/media.php';
        expect($defaults['queue']['name'])->toBeNull();
    } finally {
        putenv($previous === false ? 'MEDIA_QUEUE' : 'MEDIA_QUEUE='.$previous);
    }
});
