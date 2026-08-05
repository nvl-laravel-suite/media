<?php

declare(strict_types=1);

use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\MediaAdder;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Traits\InteractsWithMedia;

test('canonical documentation files exist and are linked from the readme', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = file_get_contents($root.'/README.md');
    $documents = [
        'docs/php-api.md',
        'docs/http-api.md',
        'docs/configuration.md',
        'docs/extending.md',
        'docs/images-and-queues.md',
        'docs/s3.md',
        'docs/commands.md',
    ];

    expect($readme)->toBeString();

    foreach ($documents as $document) {
        expect($root.'/'.$document)->toBeFile()
            ->and($readme)->toContain("]({$document})");
    }
});

test('the PHP reference covers every public integration method', function (): void {
    $root = dirname(__DIR__, 2);
    $reference = file_get_contents($root.'/docs/php-api.md');
    $surfaces = [
        MediaLibraryContract::class,
        InteractsWithMedia::class,
        MediaAdder::class,
        MediaSlot::class,
        ConversionDefinition::class,
        Media::class,
    ];

    expect($reference)->toBeString();

    foreach ($surfaces as $surface) {
        $reflection = new ReflectionClass($surface);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $surface
                || $method->getFileName() !== $reflection->getFileName()
                || $method->getName() === '__construct') {
                continue;
            }

            expect($reference)
                ->toContain($method->getName().'(');
        }
    }
});

test('the HTTP reference covers every package route name', function (): void {
    $root = dirname(__DIR__, 2);
    $reference = file_get_contents($root.'/docs/http-api.md');
    $managementRoutes = file_get_contents($root.'/routes/api.php');
    $assetRoutes = file_get_contents($root.'/routes/assets.php');

    expect($reference)->toBeString()
        ->and($managementRoutes)->toBeString()
        ->and($assetRoutes)->toBeString();

    preg_match_all("/->name\\('([^']+)'\\)/", $managementRoutes, $managementNames);
    preg_match_all("/->name\\('([^']+)'\\)/", $assetRoutes, $assetNames);

    foreach ($managementNames[1] as $name) {
        $qualifiedName = str_starts_with($name, 'nvl.media.management.')
            ? $name
            : 'nvl.media.management.'.$name;

        expect($reference)
            ->toContain($qualifiedName);
    }

    foreach ($assetNames[1] as $name) {
        $qualifiedName = str_starts_with($name, 'media.')
            ? $name
            : 'media.'.$name;

        expect($reference)
            ->toContain($qualifiedName);
    }
});

test('the extension reference covers every contract and lifecycle event', function (): void {
    $root = dirname(__DIR__, 2);
    $reference = file_get_contents($root.'/docs/extending.md');
    $surfaces = [
        ...(glob($root.'/src/Contracts/*.php') ?: []),
        ...(glob($root.'/src/Events/*.php') ?: []),
    ];

    expect($reference)->toBeString()
        ->and($surfaces)->not->toBeEmpty();

    foreach ($surfaces as $surface) {
        expect($reference)
            ->toContain(pathinfo($surface, PATHINFO_FILENAME));
    }
});

test('the configuration reference covers every top-level key and environment variable', function (): void {
    $root = dirname(__DIR__, 2);
    $reference = file_get_contents($root.'/docs/configuration.md');
    $configurationSource = file_get_contents($root.'/config/media.php');

    /** @var array<string, mixed> $configuration */
    $configuration = require $root.'/config/media.php';

    expect($reference)->toBeString()
        ->and($configurationSource)->toBeString();

    foreach (array_keys($configuration) as $key) {
        expect($reference)
            ->toContain('media.'.$key);
    }

    preg_match_all("/env\\('([A-Z0-9_]+)'/", $configurationSource, $environmentVariables);

    foreach (array_unique($environmentVariables[1]) as $environmentVariable) {
        expect($reference)
            ->toContain($environmentVariable);
    }
});

test('relative Markdown document links resolve', function (): void {
    $root = dirname(__DIR__, 2);
    $documents = [
        $root.'/README.md',
        $root.'/UPGRADING.md',
        ...(glob($root.'/docs/*.md') ?: []),
    ];

    foreach ($documents as $document) {
        $contents = file_get_contents($document);

        expect($contents)->toBeString();

        preg_match_all(
            '/\\]\\(([^)#]+\\.md)(?:#[^)]+)?\\)/',
            $contents,
            $links,
        );

        foreach ($links[1] as $link) {
            expect(dirname($document).'/'.$link)
                ->toBeFile();
        }
    }
});
