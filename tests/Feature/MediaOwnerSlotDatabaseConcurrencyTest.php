<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Actions\ReplaceOwnerMediaSlotAction;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Tests\Stubs\OwnerSlotWorkflowModel;

/**
 * Return why the real database race gate cannot run in this environment.
 */
function mediaOwnerSlotConcurrencySkipReason(): ?string
{
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
        return 'The owner-slot concurrency gate runs in the PostgreSQL/MySQL matrix.';
    }

    if (! function_exists('pcntl_exec')
        || ! function_exists('pcntl_fork')
        || ! function_exists('pcntl_waitpid')) {
        return 'The owner-slot concurrency gate requires pcntl.';
    }

    return null;
}

/**
 * Create a committed connection that is visible to both forked workers.
 */
function mediaOwnerSlotConcurrencyConnection(): string
{
    $connectionName = config('database.default');

    if (! is_string($connectionName) || $connectionName === '') {
        throw new RuntimeException('The owner-slot concurrency connection is unavailable.');
    }

    $connectionConfig = config("database.connections.{$connectionName}");

    if (! is_array($connectionConfig)) {
        throw new RuntimeException(
            'The owner-slot concurrency database configuration is unavailable.',
        );
    }

    config()->set(
        'database.connections.media_owner_slot_concurrency_target',
        $connectionConfig,
    );
    DB::purge('media_owner_slot_concurrency_target');

    return $connectionName;
}

/**
 * Insert committed owner, optional current Media, candidate Media, and association rows.
 *
 * @return array{owner: string, current: string|null, candidates: list<string>, disk_root: string}
 */
function mediaOwnerSlotConcurrencyFixture(bool $withCurrent = true): array
{
    $connection = DB::connection('media_owner_slot_concurrency_target');
    $ownerId = Str::uuid()->toString();
    $currentId = $withCurrent ? Str::uuid()->toString() : null;
    $candidateIds = [
        Str::uuid()->toString(),
        Str::uuid()->toString(),
    ];
    $diskRoot = sys_get_temp_dir().'/nvl-media-owner-slot-'.Str::uuid();
    $now = now();

    File::ensureDirectoryExists($diskRoot);
    config()->set('filesystems.disks.public.root', $diskRoot);
    Storage::forgetDisk('public');

    $connection->table('test_media_models')->insert([
        'id' => $ownerId,
        'name' => 'Concurrent owner',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $mediaIds = $currentId === null
        ? $candidateIds
        : [$currentId, ...$candidateIds];

    foreach ($mediaIds as $index => $mediaId) {
        $hash = hash('sha256', $mediaId).'.pdf';

        $connection->table(MediaTables::Media)->insert([
            'id' => $mediaId,
            'filename' => "document-{$index}.pdf",
            'hash' => $hash,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => 1_024,
            'disk' => 'public',
            'folder' => 'documents',
            'is_public' => false,
            'visibility' => 'private',
            'status' => 'available',
            'revision' => 1,
            'type' => 'document',
            'digest' => hash('sha256', "digest-{$mediaId}"),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Storage::disk('public')->put("documents/{$hash}", "document-{$index}");
    }

    if ($currentId !== null) {
        $connection->table(MediaTables::Associations)->insert([
            'id' => Str::uuid()->toString(),
            'media_id' => $currentId,
            'associable_type' => OwnerSlotWorkflowModel::class,
            'associable_id' => $ownerId,
            'collection' => 'document',
            'order' => 0,
            'is_active' => true,
            'metadata' => json_encode(['slot' => 'document'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return [
        'owner' => $ownerId,
        'current' => $currentId,
        'candidates' => $candidateIds,
        'disk_root' => $diskRoot,
    ];
}

/**
 * Run two replacement callbacks at the same process boundary.
 *
 * @param  list<Closure(): array<string, mixed>>  $workers
 * @return list<array<string, mixed>>
 */
function mediaOwnerSlotConcurrencyRunWorkers(
    string $connectionName,
    array $workers,
): array {
    $connectionConfig = config("database.connections.{$connectionName}");

    if (! is_array($connectionConfig)) {
        throw new RuntimeException(
            'The owner-slot worker database configuration is unavailable.',
        );
    }

    $gate = tempnam(sys_get_temp_dir(), 'media-owner-slot-gate-');
    $readyFiles = [];
    $resultFiles = [];

    foreach ($workers as $_worker) {
        $readyFiles[] = tempnam(sys_get_temp_dir(), 'media-owner-slot-ready-');
        $resultFiles[] = tempnam(sys_get_temp_dir(), 'media-owner-slot-result-');
    }

    if (! is_string($gate)
        || in_array(false, $readyFiles, true)
        || in_array(false, $resultFiles, true)) {
        throw new RuntimeException('The owner-slot race could not allocate IPC files.');
    }

    /** @var list<non-falsy-string> $readyFiles */
    /** @var list<non-falsy-string> $resultFiles */
    $children = [];

    try {
        foreach ($workers as $workerIndex => $worker) {
            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new RuntimeException('The owner-slot race could not fork a worker.');
            }

            if ($processId > 0) {
                $children[] = $processId;

                continue;
            }

            try {
                $workerConnectionName = "media_owner_slot_worker_{$workerIndex}";
                config()->set(
                    "database.connections.{$workerConnectionName}",
                    $connectionConfig,
                );
                config()->set('database.default', $workerConnectionName);
                config()->set('media.mutation_lock.enabled', false);
                DB::purge($workerConnectionName);
                file_put_contents($readyFiles[$workerIndex], 'ready');
                $deadline = microtime(true) + 10;

                while (file_get_contents($gate) !== 'go') {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException(
                            'The owner-slot concurrency gate timed out.',
                        );
                    }

                    usleep(10_000);
                }

                $result = ['ok' => true, ...$worker()];
            } catch (Throwable $exception) {
                $result = [
                    'ok' => false,
                    'error' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            file_put_contents(
                $resultFiles[$workerIndex],
                json_encode($result, JSON_THROW_ON_ERROR),
            );

            if (pcntl_exec('/usr/bin/true') === false) {
                exit(1);
            }
        }

        $deadline = microtime(true) + 10;

        do {
            $ready = array_reduce(
                $readyFiles,
                static fn (bool $carry, string $path): bool => $carry
                    && file_get_contents($path) === 'ready',
                true,
            );

            if ($ready) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        if (! $ready) {
            throw new RuntimeException('The owner-slot workers did not become ready.');
        }

        file_put_contents($gate, 'go');

        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child, $status);

            expect(pcntl_wifexited($status))->toBeTrue()
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }

        /** @var list<array<string, mixed>> $results */
        $results = array_map(
            static function (string $path): array {
                $decoded = json_decode(
                    (string) file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                if (! is_array($decoded)) {
                    throw new RuntimeException(
                        'An owner-slot worker returned invalid data.',
                    );
                }

                return $decoded;
            },
            $resultFiles,
        );

        return $results;
    } finally {
        foreach ([$gate, ...$readyFiles, ...$resultFiles] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

/**
 * Delete every committed concurrency fixture row.
 *
 * @param  array{owner: string, current: string|null, candidates: list<string>, disk_root: string}  $fixture
 */
function mediaOwnerSlotConcurrencyCleanup(array $fixture): void
{
    $connection = DB::connection('media_owner_slot_concurrency_target');
    $mediaIds = $fixture['current'] === null
        ? $fixture['candidates']
        : [$fixture['current'], ...$fixture['candidates']];

    $connection->table(MediaTables::Associations)
        ->whereIn('media_id', $mediaIds)
        ->delete();
    $connection->table(MediaTables::Media)
        ->whereIn('id', $mediaIds)
        ->delete();
    $connection->table('test_media_models')
        ->where('id', $fixture['owner'])
        ->delete();
    File::deleteDirectory($fixture['disk_root']);
}

it('serializes two competing replacements into one durable owner-slot winner', function (): void {
    $connectionName = mediaOwnerSlotConcurrencyConnection();
    $fixture = mediaOwnerSlotConcurrencyFixture();

    try {
        $worker = static function (string $candidateId) use ($fixture): array {
            $owner = OwnerSlotWorkflowModel::query()->findOrFail($fixture['owner']);
            $result = app(ReplaceOwnerMediaSlotAction::class)->execute(
                actor: MediaActorData::system(),
                owner: $owner,
                slot: 'document',
                mediaId: $candidateId,
            );

            return ['media_id' => $result->id];
        };
        $results = mediaOwnerSlotConcurrencyRunWorkers($connectionName, [
            static fn (): array => $worker($fixture['candidates'][0]),
            static fn (): array => $worker($fixture['candidates'][1]),
        ]);
        $connection = DB::connection('media_owner_slot_concurrency_target');
        $associations = $connection->table(MediaTables::Associations)
            ->where('associable_type', OwnerSlotWorkflowModel::class)
            ->where('associable_id', $fixture['owner'])
            ->where('collection', 'document')
            ->get();
        $winnerId = $associations->sole()->media_id;

        expect(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] !== true,
        ))->toBe([])
            ->and($associations)->toHaveCount(1)
            ->and($fixture['candidates'])->toContain($winnerId)
            ->and(Storage::disk('public')->exists(
                'documents/'.hash('sha256', (string) $winnerId).'.pdf',
            ))->toBeTrue()
            ->and($connection->table(MediaTables::Media)
                ->where('id', $winnerId)
                ->whereNull('deleted_at')
                ->exists())->toBeTrue()
            ->and($connection->table(MediaTables::Media)
                ->where('id', $fixture['current'])
                ->whereNotNull('deleted_at')
                ->exists())->toBeTrue();
    } finally {
        mediaOwnerSlotConcurrencyCleanup($fixture);
    }
})->skip(
    fn (): bool => mediaOwnerSlotConcurrencySkipReason() !== null,
    'The owner-slot concurrency gate requires PostgreSQL/MySQL and pcntl.',
);

it('serializes two competing replacements when the owner slot starts empty', function (): void {
    $connectionName = mediaOwnerSlotConcurrencyConnection();
    $fixture = mediaOwnerSlotConcurrencyFixture(withCurrent: false);

    try {
        $worker = static function (string $candidateId) use ($fixture): array {
            $owner = OwnerSlotWorkflowModel::query()->findOrFail($fixture['owner']);
            $result = app(ReplaceOwnerMediaSlotAction::class)->execute(
                actor: MediaActorData::system(),
                owner: $owner,
                slot: 'document',
                mediaId: $candidateId,
            );

            return ['media_id' => $result->id];
        };
        $results = mediaOwnerSlotConcurrencyRunWorkers($connectionName, [
            static fn (): array => $worker($fixture['candidates'][0]),
            static fn (): array => $worker($fixture['candidates'][1]),
        ]);
        $connection = DB::connection('media_owner_slot_concurrency_target');
        $associations = $connection->table(MediaTables::Associations)
            ->where('associable_type', OwnerSlotWorkflowModel::class)
            ->where('associable_id', $fixture['owner'])
            ->where('collection', 'document')
            ->get();
        $winnerId = $associations->sole()->media_id;
        $loserId = collect($fixture['candidates'])
            ->sole(static fn (string $candidateId): bool => $candidateId !== $winnerId);

        expect(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] !== true,
        ))->toBe([])
            ->and($associations)->toHaveCount(1)
            ->and($fixture['candidates'])->toContain($winnerId)
            ->and(Storage::disk('public')->exists(
                'documents/'.hash('sha256', (string) $winnerId).'.pdf',
            ))->toBeTrue()
            ->and($connection->table(MediaTables::Media)
                ->where('id', $winnerId)
                ->whereNull('deleted_at')
                ->exists())->toBeTrue()
            ->and($connection->table(MediaTables::Media)
                ->where('id', $loserId)
                ->whereNotNull('deleted_at')
                ->exists())->toBeTrue();
    } finally {
        mediaOwnerSlotConcurrencyCleanup($fixture);
    }
})->skip(
    fn (): bool => mediaOwnerSlotConcurrencySkipReason() !== null,
    'The owner-slot concurrency gate requires PostgreSQL/MySQL and pcntl.',
);
