<?php

declare(strict_types=1);
use Illuminate\Support\Arr;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Ownership\MediaOwnerCatalog;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaImport;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaMultipart;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Services\MediaScan;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Services\MediaVariations;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaDiskResolver;

test('canonical documentation files exist and are linked from the readme', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = file_get_contents($root.'/README.md');
    $documents = ['docs/php-api.md', 'docs/http-api.md', 'docs/configuration.md', 'docs/extending.md', 'docs/images-and-queues.md', 'docs/s3.md', 'docs/commands.md'];
    expect($readme)->toBeString();
    foreach ($documents as $document) {
        expect($root.'/'.$document)->toBeFile()->and($readme)->toContain("]({$document})");
    }
});
test('the packaged storage skill example uses supported configuration and resolves its disk layouts', function (): void {
    $root = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($root.'/resources/boost/skills/nvl-media/SKILL.md');
    expect(preg_match('/```php\n(.*?)\n```/s', $skill, $example))->toBe(1);
    $configuration = eval($example[1]);
    $defaults = require $root.'/config/nvl-media.php';
    expect($configuration)->toBeArray();
    foreach (array_keys($configuration) as $key) {
        expect($defaults)->toHaveKey($key);
        config(['nvl-media.'.$key => $configuration[$key]]);
    }
    expect(MediaDiskResolver::resolve(null, true))->toBe('public')->and(MediaDiskResolver::resolve(null, false))->toBe('private')->and(Media::storagePath('', 'private'))->toBe('')->and(Media::storagePath('invoices', 's3'))->toBe('assets/uploads/invoices')->and(Media::storagePath('avatars', 'public'))->toBe('media/avatars')->and((new MediaSlot('documents'))->pathTemplate)->toBe('');
});
test('the PHP reference covers every public integration method', function (): void {
    $root = dirname(__DIR__, 2);
    $reference = file_get_contents($root.'/docs/php-api.md');
    $surfaces = [MediaUploader::class, MediaAttachments::class, MediaLibrary::class, MediaReader::class, MediaMultipart::class, MediaVariations::class, MediaScan::class, MediaImport::class, MediaOwnerCatalog::class, HasNvlMedia::class, MediaRegistration::class, MediaSlot::class, ConversionDefinition::class, Media::class];
    expect($reference)->toBeString();
    foreach ($surfaces as $surface) {
        $reflection = new ReflectionClass($surface);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $surface || $method->getFileName() !== $reflection->getFileName() || $method->getName() === '__construct') {
                continue;
            }
            expect($reference)->toContain($method->getName().'(');
        }
    }
});
test('the HTTP reference describes consumer transport and the provider registers no routes', function (): void {
    $root = dirname(__DIR__, 2);
    $reference = file_get_contents($root.'/docs/http-api.md');
    $provider = file_get_contents($root.'/src/Providers/MediaServiceProvider.php');
    expect($reference)->toBeString()->toContain('registers no HTTP routes', 'explicit actor', 'signed private links')
        ->and($provider)->not->toContain('loadRoutesFrom')
        ->and(glob($root.'/routes/*.php') ?: [])->toBe([]);
});
test('the extension reference covers every contract and lifecycle event', function (): void {
    $root = dirname(__DIR__, 2);
    $reference = file_get_contents($root.'/docs/extending.md');
    $surfaces = [...glob($root.'/src/Contracts/*.php') ?: [], ...glob($root.'/src/Events/*.php') ?: []];
    expect($reference)->toBeString()->and($surfaces)->not->toBeEmpty();
    foreach ($surfaces as $surface) {
        expect($reference)->toContain(pathinfo($surface, PATHINFO_FILENAME));
    }
});
test('the configuration reference covers every top-level key and environment variable', function (): void {
    $root = dirname(__DIR__, 2);
    $reference = file_get_contents($root.'/docs/configuration.md');
    $configurationSource = file_get_contents($root.'/config/nvl-media.php');
    /** @var array<string, mixed> $configuration */
    $configuration = require $root.'/config/nvl-media.php';
    expect($reference)->toBeString()->and($configurationSource)->toBeString();
    foreach (array_keys($configuration) as $key) {
        expect($reference)->toContain('nvl-media.'.$key);
    }
    preg_match_all("/env\\('([A-Z0-9_]+)'/", $configurationSource, $environmentVariables);
    foreach (array_unique($environmentVariables[1]) as $environmentVariable) {
        expect($reference)->toContain($environmentVariable);
    }
});
test('the published common configuration includes every declared installation option', function (): void {
    $root = dirname(__DIR__, 2);
    $sections = json_decode(file_get_contents($root.'/resources/config/sections.json'), true, flags: JSON_THROW_ON_ERROR);
    $published = require $root.'/resources/config/nvl-media.php';
    foreach ($sections['configs']['nvl-media']['common'] as $key) {
        expect(Arr::has($published, $key))->toBeTrue("Missing common published config: {$key}");
    }
});
test('relative Markdown document links resolve', function (): void {
    $root = dirname(__DIR__, 2);
    $documents = [$root.'/README.md', $root.'/UPGRADING.md', ...glob($root.'/docs/*.md') ?: []];
    foreach ($documents as $document) {
        $contents = file_get_contents($document);
        expect($contents)->toBeString();
        preg_match_all('/\]\(([^)#]+\.md)(?:#[^)]+)?\)/', $contents, $links);
        foreach ($links[1] as $link) {
            if (parse_url($link, PHP_URL_SCHEME) !== null) {
                continue;
            }
            expect(dirname($document).'/'.$link)->toBeFile();
        }
    }
});
