<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase;

[$script, $vendor, $distribution, $consumer] = $argv;
$original = require $vendor.'/autoload.php';
$original->unregister();

// The installed development class map must never supply the package under test
// or an optional integration that a production consumer has not installed.
$allowedNvl = ['Nvl\\Support\\', 'Nvl\\Translatable\\'];
$allowed = static function (string $class) use ($allowedNvl): bool {
    if (str_starts_with($class, 'Aws\\') || str_starts_with($class, 'League\\Flysystem\\AwsS3V3\\')) {
        return false;
    }
    if (! str_starts_with($class, 'Nvl\\')) {
        return true;
    }
    foreach ($allowedNvl as $prefix) {
        if (str_starts_with($class, $prefix) && ! str_contains($class, '\\Tests\\')) {
            return true;
        }
    }

    return false;
};
$loader = new ClassLoader;
$loader->addClassMap(array_filter($original->getClassMap(), static fn (string $file, string $class): bool => $allowed($class), ARRAY_FILTER_USE_BOTH));
foreach ($original->getPrefixesPsr4() as $prefix => $paths) {
    if ($allowed($prefix)) {
        $loader->setPsr4($prefix, $paths);
    }
}
$manifest = json_decode(file_get_contents($distribution.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['autoload']['psr-4'] as $prefix => $relative) {
    $loader->setPsr4($prefix, $distribution.'/'.$relative);
}
$loader->register();

$optional = class_exists(OwnershipServiceProvider::class)
    || class_exists(S3Client::class)
    || class_exists(AwsS3V3Adapter::class);
if ($optional) {
    throw new RuntimeException('The distribution smoke test accidentally loaded an optional dependency.');
}

$filesystem = new Filesystem;
foreach (['bootstrap/cache', 'config', 'database/migrations', 'storage/framework/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
    $filesystem->ensureDirectoryExists($consumer.'/'.$directory);
}
$filesystem->put($consumer.'/composer.json', json_encode(['name' => 'consumer/media-publication', 'extra' => ['laravel' => ['dont-discover' => ['*']]]], JSON_THROW_ON_ERROR));

$case = new class('smoke') extends TestCase
{
    protected function getApplicationBasePath(): string
    {
        global $consumer;

        return $consumer;
    }

    protected function getPackageProviders($app): array
    {
        return [LocaleServiceProvider::class, TranslatableServiceProvider::class, MediaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set(['app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
    }

    public function smoke(): void {}
};
$app = $case->createApplication();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, (string) $error);
    exit(1);
});

foreach (['nvl-media-skills', 'nvl-media-config', 'nvl-media-migrations'] as $tag) {
    if (Artisan::call('vendor:publish', ['--tag' => $tag, '--force' => true]) !== 0) {
        throw new RuntimeException('Failed publishing '.$tag.': '.Artisan::output());
    }
}
$skills = ServiceProvider::pathsToPublish(MediaServiceProvider::class, 'nvl-media-skills');
$resolvedSkills = [];
foreach ($skills as $source => $destination) {
    $resolvedSkills[realpath($source)] = $destination;
}
if (($resolvedSkills[realpath($distribution.'/resources/boost/skills')] ?? null) !== $consumer.'/.agents/skills') {
    throw new RuntimeException('The skill publication does not resolve from the extracted distribution into the host: '.json_encode($skills));
}
$sections = json_decode(file_get_contents($distribution.'/resources/config/sections.json'), true, flags: JSON_THROW_ON_ERROR);
$published = require $consumer.'/config/nvl-media.php';
foreach ($sections['configs']['nvl-media']['common'] as $key) {
    if (! Arr::has($published, $key)) {
        throw new RuntimeException('The published configuration lacks schema key '.$key);
    }
}
$skill = $consumer.'/.agents/skills/nvl-media/SKILL.md';
$ui = $consumer.'/.agents/skills/nvl-media/agents/openai.yaml';
echo json_encode([
    'optional_dependencies' => $optional,
    'media_source' => (new ReflectionClass(MediaServiceProvider::class))->getFileName(),
    'skill_published' => is_file($skill) && is_file($ui) && hash_file('sha256', $skill) === hash_file('sha256', $distribution.'/resources/boost/skills/nvl-media/SKILL.md') && hash_file('sha256', $ui) === hash_file('sha256', $distribution.'/resources/boost/skills/nvl-media/agents/openai.yaml'),
    'config_published' => is_file($consumer.'/config/nvl-media.php'),
    'migrations_published' => count(glob($consumer.'/database/migrations/*nvl_media*.php')),
    'delivery_default' => config('nvl-media.delivery.defaults.private.mode'),
], JSON_THROW_ON_ERROR);
