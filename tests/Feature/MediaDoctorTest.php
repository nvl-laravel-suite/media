<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Exceptions\MediaUploadException;
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
