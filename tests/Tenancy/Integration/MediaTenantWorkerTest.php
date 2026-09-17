<?php

declare(strict_types=1);

use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('restores Media tenant context in a real database queue worker', function (): void {
    $root = dirname(__DIR__, 6);
    $source = $root.'/packages/nvl/media/tests/Fixtures/tenancy-consumer';
    $fixture = sys_get_temp_dir().'/nvl-media-worker-'.bin2hex(random_bytes(8));
    $database = $fixture.'/database.sqlite';
    $queue = 'media-tenant-proof-'.bin2hex(random_bytes(6));
    $files = new Filesystem;

    try {
        $files->mirror($source, $fixture);
        $files->mkdir([
            $fixture.'/app',
            $fixture.'/bootstrap/cache',
            $fixture.'/storage/app/media',
            $fixture.'/storage/framework/cache/data',
            $fixture.'/storage/logs',
        ]);
        touch($database);

        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'NVL_TEST_SUITE_ROOT' => $root,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'database',
            'MEDIA_QUEUE' => $queue,
            'MEDIA_STORAGE_ROOT' => $fixture.'/storage/app/media',
        ];
        $setup = new Process([
            PHP_BINARY,
            $fixture.'/artisan.php',
            'media-tenancy-fixture:setup',
            '--queue='.$queue,
            '--no-interaction',
        ], $fixture, $environment, timeout: 60);
        $setup->run();

        expect($setup->isSuccessful())->toBeTrue($setup->getOutput().$setup->getErrorOutput())
            ->and($setup->getOutput())->toContain('driver=sqlite', 'setup=ok', 'queue='.$queue);

        $worker = new Process([
            PHP_BINARY,
            $fixture.'/artisan.php',
            'queue:work',
            'database',
            '--queue='.$queue,
            '--stop-when-empty',
            '--tries=2',
            '--timeout=30',
            '--sleep=0',
            '--no-interaction',
        ], $fixture, $environment, timeout: 60);
        $worker->run();

        expect($worker->isSuccessful())->toBeTrue($worker->getOutput().$worker->getErrorOutput());
        $retryDatabase = new PDO('sqlite:'.$database);
        $retryDatabase->exec('update jobs set available_at = 0');
        $retryWorker = new Process([
            PHP_BINARY,
            $fixture.'/artisan.php',
            'queue:work',
            'database',
            '--queue='.$queue,
            '--stop-when-empty',
            '--tries=2',
            '--timeout=30',
            '--sleep=0',
            '--no-interaction',
        ], $fixture, $environment, timeout: 60);
        $retryWorker->run();
        expect($retryWorker->isSuccessful())->toBeTrue($retryWorker->getOutput().$retryWorker->getErrorOutput());

        $pdo = new PDO('sqlite:'.$database);
        $variations = $pdo->query(
            'select tenant_id, label, storage_path, status from px_media_image_variations order by tenant_id, label',
        )->fetchAll(PDO::FETCH_ASSOC);
        $successful = array_values(array_filter(
            $variations,
            static fn (array $variation): bool => $variation['status'] === 'available',
        ));
        $failed = $pdo->query('select exception from failed_jobs order by id')->fetchAll(PDO::FETCH_COLUMN);
        $corrupt = array_values(array_filter(
            $failed,
            static fn (string $exception): bool => str_contains($exception, 'A carried envelope differs from the queued tenant boundary.'),
        ));
        expect($successful)->toHaveCount(2, json_encode([
            'variations' => $variations,
            'failed' => array_map(static fn (string $exception): string => substr($exception, 0, 500), $failed),
            'jobs' => $pdo->query('select id, queue, attempts from jobs')->fetchAll(PDO::FETCH_ASSOC),
            'media' => $pdo->query('select id, tenant_id, status from px_media')->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_THROW_ON_ERROR))
            ->and(array_column($successful, 'tenant_id'))->toBe([
                MediaTenancyScenario::A,
                MediaTenancyScenario::B,
            ])
            ->and($successful[0]['storage_path'])->toContain('/tenants/'.MediaTenancyScenario::A.'/')
            ->and($successful[1]['storage_path'])->toContain('/tenants/'.MediaTenancyScenario::B.'/')
            ->and(array_column($variations, 'label'))->not->toContain('stale', 'corrupt-before-read')
            ->and($failed)->toHaveCount(2, json_encode([
                'jobs' => $pdo->query('select id, queue, attempts, available_at from jobs')->fetchAll(PDO::FETCH_ASSOC),
            ], JSON_THROW_ON_ERROR))
            ->and($corrupt)->toHaveCount(1, implode("\n---\n", $failed))
            ->and($corrupt[0])->toContain(TenantBoundaryViolation::class)
            ->and((int) $pdo->query('select count(*) from jobs')->fetchColumn())->toBe(0)
            ->and($pdo->query("select tenant_id from media_tenant_worker_probes where probe_key = 'worker-scope'")->fetchColumn())->toBeNull();
    } finally {
        $files->remove($fixture);
    }
});
