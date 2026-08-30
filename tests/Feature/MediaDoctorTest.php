<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Enums\MediaOwnerSlotOperationStatus;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDoctor;
use Nvl\Media\Services\MediaScannerPolicy;
use Nvl\Media\Services\S3MultipartUploadGateway;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Tests\Stubs\NonLockingMediaDoctorStore;
use Nvl\Media\Tests\Stubs\OwnerSlotWorkflowModel;

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

it('treats the consumer copy metadata allowlist as an exact config replacement', function () {
    config([
        'media.owner_slots.copy.metadata_keys' => ['format'],
    ]);

    $mergeConfiguration = new ReflectionMethod(
        MediaServiceProvider::class,
        'mergeConfiguration',
    );
    $mergeConfiguration->invoke(new MediaServiceProvider(app()));

    expect(config('media.owner_slots.copy.metadata_keys'))->toBe(['format']);
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

it('rejects a non-string owner-slot operation connection', function (): void {
    config([
        'media.owner_slots.idempotency.connection' => ['sqlite'],
    ]);

    expect(fn (): ?string => MediaConfiguration::ownerSlotOperationConnection())
        ->toThrow(InvalidArgumentException::class, 'must be a string or null');
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

it('registers bounded owner-slot pruning and protects production retention', function (): void {
    $expired = MediaOwnerSlotOperation::query()->forceCreate([
        'idempotency_key' => Str::uuid()->toString(),
        'owner_type' => OwnerSlotWorkflowModel::class,
        'owner_id' => 'expired-owner',
        'slot' => 'document',
        'operation' => MediaOwnerSlotOperationType::Clear,
        'request_hash' => hash('sha256', 'expired-owner-slot-operation'),
        'status' => MediaOwnerSlotOperationStatus::Completed,
        'completed_at' => now()->subDays(8),
    ]);
    $retained = MediaOwnerSlotOperation::query()->forceCreate([
        'idempotency_key' => Str::uuid()->toString(),
        'owner_type' => OwnerSlotWorkflowModel::class,
        'owner_id' => 'retained-owner',
        'slot' => 'document',
        'operation' => MediaOwnerSlotOperationType::Clear,
        'request_hash' => hash('sha256', 'retained-owner-slot-operation'),
        'status' => MediaOwnerSlotOperationStatus::Completed,
        'completed_at' => now()->subDays(2),
    ]);
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        $this->artisan('nvl:media:owner-slots:prune', ['--chunk' => 100])
            ->expectsOutputToContain('Pruned owner-slot operations: 1')
            ->assertSuccessful();

        expect(MediaOwnerSlotOperation::query()->whereKey($expired->id)->exists())->toBeFalse()
            ->and(MediaOwnerSlotOperation::query()->whereKey($retained->id)->exists())->toBeTrue();

        $this->artisan('nvl:media:owner-slots:prune', [
            '--days' => 1,
            '--chunk' => 100,
        ])
            ->expectsOutputToContain('--force is required')
            ->assertFailed();

        expect(MediaOwnerSlotOperation::query()->whereKey($retained->id)->exists())->toBeTrue();

        $this->artisan('nvl:media:owner-slots:prune', [
            '--days' => 1,
            '--chunk' => 100,
            '--force' => true,
        ])
            ->expectsOutputToContain('Pruned owner-slot operations: 1')
            ->assertSuccessful();

        expect(MediaOwnerSlotOperation::query()->whereKey($retained->id)->exists())->toBeFalse();

        $this->artisan('nvl:media:owner-slots:prune', ['--chunk' => 1_001])
            ->expectsOutputToContain('between 1 and 1000')
            ->assertFailed();

        $this->artisan('nvl:media:owner-slots:prune', [
            '--days' => 0,
            '--chunk' => 100,
        ])
            ->expectsOutputToContain('between 1 and 36500')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});

it('reports a mutation store without atomic locks', function (): void {
    Cache::extend(
        'media-non-locking',
        fn (): Repository => new Repository(new NonLockingMediaDoctorStore),
    );
    config([
        'cache.stores.media-non-locking' => ['driver' => 'media-non-locking'],
        'media.mutation_lock.store' => 'media-non-locking',
    ]);

    $check = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'locks.mutation.atomic');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->severity)->toBe('error');
});

it('reports observed owner-slot registrations that no longer resolve', function (): void {
    MediaOwnerSlotOperation::query()->forceCreate([
        'idempotency_key' => Str::uuid()->toString(),
        'owner_type' => OwnerSlotWorkflowModel::class,
        'owner_id' => 'missing-slot-owner',
        'slot' => 'missing',
        'operation' => MediaOwnerSlotOperationType::Clear,
        'request_hash' => hash('sha256', 'missing-owner-slot-registration'),
        'status' => MediaOwnerSlotOperationStatus::Completed,
        'completed_at' => now(),
    ]);

    $check = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'owner_slots.registrations');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->severity)->toBe('error')
        ->and($check->message)->toContain('missing');
});

it('bounds owner-slot registration diagnostics before resolving entries', function (): void {
    foreach (range(1, 101) as $index) {
        MediaOwnerSlotOperation::query()->forceCreate([
            'idempotency_key' => Str::uuid()->toString(),
            'owner_type' => 'MissingOwner'.$index,
            'owner_id' => 'owner-'.$index,
            'slot' => 'document',
            'operation' => MediaOwnerSlotOperationType::Clear,
            'request_hash' => hash('sha256', 'owner-slot-registration-'.$index),
            'status' => MediaOwnerSlotOperationStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    $check = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'owner_slots.registrations');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->message)->toContain('at most 100 observed owner type and slot pairs');
});

it('reports unavailable owner models in observed owner-slot registrations', function (): void {
    MediaOwnerSlotOperation::query()->forceCreate([
        'idempotency_key' => Str::uuid()->toString(),
        'owner_type' => 'MissingOwnerModel',
        'owner_id' => 'missing-owner',
        'slot' => 'document',
        'operation' => MediaOwnerSlotOperationType::Clear,
        'request_hash' => hash('sha256', 'missing-owner-slot-model'),
        'status' => MediaOwnerSlotOperationStatus::Completed,
        'completed_at' => now(),
    ]);

    $check = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'owner_slots.registrations');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->message)->toContain(
            'MissingOwnerModel:document (owner model is unavailable)',
        );
});

it('falls back to the default mutation cache and fails closed for an unknown store', function (): void {
    Cache::extend(
        'media-default-non-locking',
        fn (): Repository => new Repository(new NonLockingMediaDoctorStore),
    );
    config([
        'cache.default' => 'media-default-non-locking',
        'cache.stores.media-default-non-locking' => [
            'driver' => 'media-default-non-locking',
        ],
        'media.mutation_lock.store' => null,
    ]);

    $fallbackCheck = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'locks.mutation.atomic');

    expect($fallbackCheck)->not->toBeNull()
        ->and($fallbackCheck->passed)->toBeFalse();

    config([
        'cache.default' => [],
        'media.mutation_lock.store' => '',
    ]);

    $malformedDefaultCheck = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'locks.mutation.atomic');

    expect($malformedDefaultCheck)->not->toBeNull()
        ->and($malformedDefaultCheck->passed)->toBeFalse();

    config(['media.mutation_lock.store' => 'missing-media-cache-store']);

    $missingStoreCheck = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'locks.mutation.atomic');

    expect($missingStoreCheck)->not->toBeNull()
        ->and($missingStoreCheck->passed)->toBeFalse();
});
