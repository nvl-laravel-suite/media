<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\ServiceProvider;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDoctor;
use Nvl\Media\Services\MediaScannerPolicy;
use Nvl\Media\Services\S3MultipartUploadGateway;

it('reports a healthy standalone installation as machine-readable output', function () {
    $this->artisan('nvl:media:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertSuccessful();
});

it('registers timestamp-aware migration publishing and warns about duplicate ownership', function () {
    $migrationPath = realpath(dirname(__DIR__, 2).'/database/migrations');
    $publishableMigrationPaths = array_map(
        static fn (string $path): string|false => realpath($path),
        ServiceProvider::publishableMigrationPaths(),
    );

    expect(MediaServiceProvider::pathsToPublish(
        MediaServiceProvider::class,
        'media-migrations',
    ))->not->toBeEmpty()
        ->and($publishableMigrationPaths)->toContain($migrationPath);

    $published = database_path(
        'migrations/2099_01_01_000000_create_media_table.php',
    );
    file_put_contents($published, "<?php\n");

    try {
        $check = collect(app(MediaDoctor::class)->inspect())
            ->firstWhere('key', 'migrations.ownership');

        expect($check)
            ->not->toBeNull()
            ->passed->toBeFalse()
            ->severity->toBe('warning')
            ->message->toContain('create_media_table');

        $this->artisan('nvl:media:doctor', ['--format' => 'json'])
            ->assertSuccessful();
        $this->artisan('nvl:media:doctor', [
            '--strict' => true,
            '--format' => 'json',
        ])->assertFailed();
    } finally {
        unlink($published);
    }
});

it('fails closed when production scanning is required without a scanner binding', function () {
    config([
        'media.scanner.required' => true,
        'media.scanner.allow_noop' => false,
    ]);

    expect(fn () => app(MediaScannerPolicy::class)->assertReady())
        ->toThrow(MediaUploadException::class, 'no production MediaContentScanner');

    $this->artisan('nvl:media:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertFailed();
});

it('rejects an active S3 disk without a bucket', function () {
    config([
        'media.disk' => 's3',
        'media.allowed_disks' => ['s3'],
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'bucket' => null,
            'throw' => true,
        ],
    ]);

    $this->artisan('nvl:media:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertFailed();
});

it('rejects queue retry windows shorter than media job timeouts', function () {
    config([
        'media.queue.enabled' => true,
        'media.queue.connection' => 'database',
        'media.queue.jobs.generate.timeout' => 60,
        'queue.connections.database' => [
            'driver' => 'database',
            'retry_after' => 30,
        ],
    ]);

    $this->artisan('nvl:media:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertFailed();
});

it('rejects production remote sources without connected-IP attestation', function () {
    config([
        'media.sources.remote.enabled' => true,
        'media.sources.remote.verify_connected_ip' => false,
    ]);

    $checks = collect(app(MediaDoctor::class)->inspect(true));
    $check = $checks->firstWhere('key', 'sources.remote.configuration');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->severity)->toBe('error');
});

it('accepts the supported production multipart safety contract', function () {
    config([
        'media.multipart.enabled' => true,
        'media.multipart.required_scan' => true,
        'media.multipart.lock.store' => 'media-central',
        'cache.stores.media-central' => ['driver' => 'redis'],
    ]);
    app()->instance(
        MultipartUploadGateway::class,
        new S3MultipartUploadGateway(app(MediaDiskGateway::class)),
    );
    app()->instance(MediaContentScanner::class, new class implements MediaContentScanner
    {
        public function scan(UploadedFile $file): void {}
    });

    $checks = collect(app(MediaDoctor::class)->inspect(true))
        ->whereIn('key', [
            'multipart.gateway.recoverable',
            'multipart.lock.central',
            'multipart.scanner.attestation',
        ]);

    expect($checks)->toHaveCount(3)
        ->and($checks->every(
            static fn (object $check): bool => $check->passed === true,
        ))->toBeTrue();
});

it('reports a missing configured owner-slot operation ledger', function (): void {
    config([
        'media.owner_slots.idempotency.table' => 'missing_media_owner_slot_operations',
    ]);

    $check = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere(
            'key',
            'schema.table.missing_media_owner_slot_operations',
        );

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->severity)->toBe('error');
});

it('reports invalid owner-slot operation storage configuration', function (): void {
    config([
        'media.owner_slots.idempotency.table' => 'unsafe-name',
    ]);

    $check = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'schema.connection');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->severity)->toBe('error')
        ->and($check->message)->toContain('safe table name');
});

it('reports invalid owner-slot idempotency lifecycle bounds', function (): void {
    config([
        'media.owner_slots.idempotency.processing_timeout_minutes' => 0,
        'media.owner_slots.idempotency.retention_days' => 0,
        'media.owner_slots.idempotency.prune_chunk' => 1_001,
    ]);

    $check = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'owner_slots.idempotency.bounds');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->severity)->toBe('error');
});
