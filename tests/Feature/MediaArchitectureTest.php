<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

test('media controllers remain final and delegate bulk record queries to the query service', function (): void {
    $filesystem = new Filesystem;
    $controllerFiles = $filesystem->allFiles(__DIR__.'/../../src/Http/Controllers');

    foreach ($controllerFiles as $controllerFile) {
        $contents = $filesystem->get($controllerFile->getPathname());

        expect($contents)
            ->toMatch('/final class [A-Za-z0-9_]+Controller extends /')
            ->and($contents)->not->toContain("Media::whereIn('id'");
    }
});

test('media rollback handling supports the original Laravel 12 transaction record API', function (): void {
    $filesystem = new Filesystem;

    foreach ([
        __DIR__.'/../../src/Services/MediaFileEffectScheduler.php',
        __DIR__.'/../../src/Services/MediaMutationLock.php',
    ] as $serviceFile) {
        expect($filesystem->get($serviceFile))->not->toContain('addCallbackForRollback');
    }
});
