<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->packageRoot = dirname(__DIR__, 2);
    $this->distributionRoot = sys_get_temp_dir().'/nvl-media-distribution-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($this->distributionRoot.'/source', 0755, true);

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->packageRoot, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS)) as $file) {
        $relative = substr($file->getPathname(), strlen($this->packageRoot) + 1);
        if (! $file->isFile() || preg_match('~^(?:vendor|\.temp)/~', $relative)) {
            continue;
        }
        $destination = $this->distributionRoot.'/source/'.$relative;
        $filesystem->ensureDirectoryExists(dirname($destination));
        $filesystem->copy($file->getPathname(), $destination);
    }

    foreach (['vendor/should-not-ship.php', 'composer.lock', '.temp/cache', 'build/output', 'coverage/report', '.phpunit.cache/test-results', '.phpunit.result.cache', '.DS_Store', 'src/.DS_Store'] as $excluded) {
        $destination = $this->distributionRoot.'/source/'.$excluded;
        $filesystem->ensureDirectoryExists(dirname($destination));
        $filesystem->put($destination, 'development artifact');
    }

    $process = new Process(['composer', '--working-dir='.$this->distributionRoot.'/source', 'archive', '--format=tar', '--dir='.$this->distributionRoot, '--file=nvl-media', '--no-interaction']);
    $process->mustRun();
    $this->archive = new PharData($this->distributionRoot.'/nvl-media.tar');
    $this->archive->extractTo($this->distributionRoot.'/dist');
});

afterEach(function (): void {
    unset($this->archive);
    (new Filesystem)->deleteDirectory($this->distributionRoot);
});

it('ships complete runtime and integration resources while excluding development artifacts', function (): void {
    foreach (['LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'UPGRADING.md', 'composer.json', 'config/nvl-media.php', 'src/Enums/MediaDeliveryMode.php', 'src/Services/MediaDeliveryPolicy.php', 'resources/config/nvl-media.php', 'resources/config/sections.json', 'resources/consumer-api.json', 'resources/event-catalog.json', 'resources/boost/skills/nvl-media/SKILL.md', 'resources/boost/skills/nvl-media/agents/openai.yaml'] as $required) {
        expect(isset($this->archive[$required]))->toBeTrue('Missing distribution file: '.$required)
            ->and($this->archive[$required]->getContent())->toBe(file_get_contents($this->packageRoot.'/'.$required));
    }

    foreach (['src', 'config', 'database', 'lang', 'resources', 'docs'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->packageRoot.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getFilename() === '.DS_Store') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($this->packageRoot) + 1);
            expect(isset($this->archive[$relative]))->toBeTrue('Missing distribution file: '.$relative)
                ->and($this->archive[$relative]->getContent())->toBe(file_get_contents($file->getPathname()));
        }
    }

    foreach (['vendor', 'composer.lock', 'tests', '.temp', 'build', 'coverage', '.phpunit.cache', '.phpunit.result.cache', '.DS_Store', 'src/.DS_Store', '.gitattributes', '.gitignore', 'composer-dependency-analyser.php', 'phpstan.neon.dist', 'phpunit.xml.dist'] as $excluded) {
        expect(isset($this->archive[$excluded]))->toBeFalse('Unexpected distribution entry: '.$excluded);
    }
});

it('boots the extracted package and publishes its skills without optional integrations', function (): void {
    $vendor = dirname(InstalledVersions::getInstallPath('laravel/framework') ?? throw new RuntimeException('Laravel test dependencies are not installed.'), 2);
    $process = new Process([PHP_BINARY, $this->packageRoot.'/tests/Fixtures/Packaging/distribution-consumer.php', $vendor, $this->distributionRoot.'/dist', $this->distributionRoot.'/consumer']);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['optional_dependencies'])->toBeFalse()
        ->and($result['media_source'])->toBe(realpath($this->distributionRoot.'/dist/src/Providers/MediaServiceProvider.php'))
        ->and($result['skill_published'])->toBeTrue()
        ->and($result['config_published'])->toBeTrue()
        ->and($result['migrations_published'])->toBe(6)
        ->and($result['delivery_default'])->toBe('application');
});
