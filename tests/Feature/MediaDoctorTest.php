<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaOwnerSlotOperationStatus;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDoctor;
use Nvl\Media\Services\MediaScannerPolicy;
use Nvl\Media\Services\S3MultipartUploadGateway;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Tests\Stubs\NonLockingMediaDoctorStore;
use Nvl\Media\Tests\Stubs\OwnerSlotWorkflowModel;

function createMediaDoctorPathSample(array $overrides = []): Media
{
    return Media::query()->forceCreate(array_merge([
        'filename' => 'doctor-sample.txt',
        'hash' => Str::uuid()->toString().'.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => 6,
        'disk' => 'public',
        'folder' => 'doctor',
        'is_public' => false,
        'type' => MediaType::DOCUMENT,
        'digest' => hash('sha256', 'sample'),
    ], $overrides));
}

function mediaDoctorPersistedPathCheck(): object
{
    return collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'storage.persisted_paths');
}

it('reports a healthy standalone installation as machine-readable output', function () {
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(Schema::hasColumn(MediaTables::Media, 'storage_path'))->toBeFalse();

    $this->artisan('nvl:media:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertSuccessful();

    $sampleQuery = collect($queries)->first(
        static fn (string $query): bool => str_contains($query, MediaTables::Media)
            && str_contains($query, 'deleted_at')
            && str_contains($query, 'order by'),
    );

    expect($sampleQuery)->not->toBeNull()
        ->and($sampleQuery)->not->toContain('storage_path');
});

describe('persisted storage path diagnostics', function (): void {
    beforeEach(function (): void {
        Storage::fake('public');
        config([
            'nvl-media.disk' => 'public',
            'nvl-media.allowed_disks' => ['public'],
            'nvl-media.root_folder' => 'media',
            'nvl-media.adoption.path_sample_size' => 25,
            'nvl-media.delete_files_on_media_delete' => true,
        ]);
    });

    it('passes for a live media record whose canonical object exists', function (): void {
        $media = createMediaDoctorPathSample();
        Storage::disk($media->disk)->put($media->buildPath(), 'sample');

        $check = mediaDoctorPersistedPathCheck();

        expect($check)->not->toBeNull()
            ->and($check->passed)->toBeTrue();

        $this->artisan('nvl:media:doctor', [
            '--strict' => true,
            '--format' => 'json',
        ])->assertSuccessful();
    });

    it('fails strictly for a live media record whose canonical object is missing', function (): void {
        $media = createMediaDoctorPathSample([
            'hash' => 'missing-live.txt',
        ]);

        $check = mediaDoctorPersistedPathCheck();

        expect($check)->not->toBeNull()
            ->and($check->passed)->toBeFalse()
            ->and($check->message)->toContain(
                $media->disk.':'.$media->buildPath(),
            );

        $this->artisan('nvl:media:doctor', [
            '--strict' => true,
            '--format' => 'json',
        ])->assertFailed();
    });

    it('ignores a tombstone whose object was removed by the canonical deletion action', function (): void {
        $media = createMediaDoctorPathSample([
            'hash' => 'deleted-by-action.txt',
        ]);
        Storage::disk($media->disk)->put($media->buildPath(), 'sample');

        app(DeleteMediaAction::class)->execute($media);

        expect(Media::query()->find($media->id))->toBeNull()
            ->and(Media::withTrashed()->find($media->id)?->trashed())->toBeTrue();
        Storage::disk($media->disk)->assertMissing($media->buildPath());

        $check = mediaDoctorPersistedPathCheck();

        expect($check)->not->toBeNull()
            ->and($check->passed)->toBeTrue()
            ->and($check->message)->toContain('No live persisted media rows');

        $this->artisan('nvl:media:doctor', [
            '--strict' => true,
            '--format' => 'json',
        ])->assertSuccessful();
    });

    it('checks live objects in a mixture without requiring tombstone objects', function (): void {
        $tombstone = createMediaDoctorPathSample([
            'hash' => 'mixture-tombstone.txt',
        ]);
        Storage::disk($tombstone->disk)->put($tombstone->buildPath(), 'deleted');
        app(DeleteMediaAction::class)->execute($tombstone);

        $live = createMediaDoctorPathSample([
            'hash' => 'mixture-live.txt',
        ]);
        Storage::disk($live->disk)->put($live->buildPath(), 'live');

        expect(mediaDoctorPersistedPathCheck()->passed)->toBeTrue();

        Storage::disk($live->disk)->delete($live->buildPath());

        $missingLive = mediaDoctorPersistedPathCheck();

        expect($missingLive->passed)->toBeFalse()
            ->and($missingLive->message)->toContain(
                $live->disk.':'.$live->buildPath(),
            )
            ->and($missingLive->message)->not->toContain(
                $tombstone->disk.':'.$tombstone->buildPath(),
            );
    });

    it('does not let earlier tombstones consume the live-record sample limit', function (): void {
        config(['nvl-media.adoption.path_sample_size' => 1]);

        $tombstone = createMediaDoctorPathSample([
            'id' => '00000000-0000-4000-8000-000000000001',
            'hash' => 'first-tombstone.txt',
        ]);
        Storage::disk($tombstone->disk)->put($tombstone->buildPath(), 'deleted');
        app(DeleteMediaAction::class)->execute($tombstone);

        $live = createMediaDoctorPathSample([
            'id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'hash' => 'sampled-live.txt',
        ]);

        $check = mediaDoctorPersistedPathCheck();

        expect($check->passed)->toBeFalse()
            ->and($check->message)->toContain(
                $live->disk.':'.$live->buildPath(),
            )
            ->and($check->message)->not->toContain(
                $tombstone->disk.':'.$tombstone->buildPath(),
            );
    });

    it('retains storage exception details with the affected disk and path', function (): void {
        $media = createMediaDoctorPathSample([
            'disk' => 'missing-doctor-disk',
            'hash' => 'storage-error.txt',
        ]);

        $check = mediaDoctorPersistedPathCheck();

        expect($check->passed)->toBeFalse()
            ->and($check->message)->toContain(
                $media->disk.':'.$media->buildPath(),
            )
            ->and($check->message)->toContain('Disk [missing-doctor-disk]');
    });
});

it('registers timestamp-aware migration publishing and warns about duplicate ownership', function () {
    $migrationPath = realpath(dirname(__DIR__, 2).'/database/migrations');
    $publishableMigrationPaths = array_map(
        static fn (string $path): string|false => realpath($path),
        ServiceProvider::publishableMigrationPaths(),
    );

    expect(MediaServiceProvider::pathsToPublish(
        MediaServiceProvider::class,
        'nvl-media-migrations',
    ))->not->toBeEmpty()
        ->and($publishableMigrationPaths)->toContain($migrationPath);

    $published = database_path(
        'migrations/2099_01_01_000000_nvl_media_create_media_table.php',
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
        'nvl-media.owner_slots.copy.metadata_keys' => ['format'],
    ]);

    $mergeConfiguration = new ReflectionMethod(
        MediaServiceProvider::class,
        'mergeConfiguration',
    );
    $mergeConfiguration->invoke(new MediaServiceProvider(app()));

    expect(config('nvl-media.owner_slots.copy.metadata_keys'))->toBe(['format']);
});

it('fails closed when production scanning is required without a scanner binding', function () {
    config([
        'nvl-media.scanner.required' => true,
        'nvl-media.scanner.allow_noop' => false,
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
        'nvl-media.disk' => 's3',
        'nvl-media.allowed_disks' => ['s3'],
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
        'nvl-media.queue.enabled' => true,
        'nvl-media.queue.connection' => 'database',
        'nvl-media.queue.jobs.generate.timeout' => 60,
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
        'nvl-media.sources.remote.enabled' => true,
        'nvl-media.sources.remote.verify_connected_ip' => false,
    ]);

    $checks = collect(app(MediaDoctor::class)->inspect(true));
    $check = $checks->firstWhere('key', 'sources.remote.configuration');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->severity)->toBe('error');
});

it('accepts the supported production multipart safety contract', function () {
    config([
        'nvl-media.multipart.enabled' => true,
        'nvl-media.multipart.required_scan' => true,
        'nvl-media.multipart.lock.store' => 'media-central',
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
        'nvl-media.owner_slots.idempotency.table' => 'missing_media_owner_slot_operations',
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
        'nvl-media.owner_slots.idempotency.table' => 'unsafe-name',
    ]);

    $check = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'schema.connection');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse()
        ->and($check->severity)->toBe('error')
        ->and($check->message)->toContain('safe table identifier');
});

it('rejects a non-string owner-slot operation connection', function (): void {
    config([
        'nvl-media.owner_slots.idempotency.connection' => ['sqlite'],
    ]);

    expect(fn (): ?string => MediaConfiguration::ownerSlotOperationConnection())
        ->toThrow(InvalidArgumentException::class, 'must be a connection name or null');
});

it('reports invalid owner-slot idempotency lifecycle bounds', function (): void {
    config([
        'nvl-media.owner_slots.idempotency.processing_timeout_minutes' => 0,
        'nvl-media.owner_slots.idempotency.retention_days' => 0,
        'nvl-media.owner_slots.idempotency.prune_chunk' => 1_001,
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
        'nvl-media.mutation_lock.store' => 'media-non-locking',
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
        'nvl-media.mutation_lock.store' => null,
    ]);

    $fallbackCheck = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'locks.mutation.atomic');

    expect($fallbackCheck)->not->toBeNull()
        ->and($fallbackCheck->passed)->toBeFalse();

    config([
        'cache.default' => [],
        'nvl-media.mutation_lock.store' => null,
    ]);

    $malformedDefaultCheck = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'locks.mutation.atomic');

    expect($malformedDefaultCheck)->not->toBeNull()
        ->and($malformedDefaultCheck->passed)->toBeFalse();

    config(['nvl-media.mutation_lock.store' => 'missing-media-cache-store']);

    $missingStoreCheck = collect(app(MediaDoctor::class)->inspect())
        ->firstWhere('key', 'locks.mutation.atomic');

    expect($missingStoreCheck)->not->toBeNull()
        ->and($missingStoreCheck->passed)->toBeFalse();
});

it('validates the explicit Spatie permission bridge without activating generic grants', function (): void {
    config(['nvl-media.authorization.spatie_permission.enabled' => 'true']);
    $check = collect(app(MediaDoctor::class)->inspect())->firstWhere('key', 'adoption.spatie_permission');
    expect($check->severity)->toBe('error')->and($check->passed)->toBeFalse();
    config(['nvl-media.authorization.spatie_permission.enabled' => true, 'nvl-media.authorization.spatie_permission.global_permission' => ['invalid']]);
    $check = collect(app(MediaDoctor::class)->inspect())->firstWhere('key', 'adoption.spatie_permission');
    expect($check->severity)->toBe('error')->and($check->passed)->toBeFalse();
    config(['nvl-media.authorization.spatie_permission.global_permission' => 'host.deliberate-media-grant']);
    $check = collect(app(MediaDoctor::class)->inspect())->firstWhere('key', 'adoption.spatie_permission');
    expect($check->severity)->toBe('info')->and($check->passed)->toBeTrue()
        ->and($check->message)->toContain('host.deliberate-media-grant', 'deliberately configured');
});
