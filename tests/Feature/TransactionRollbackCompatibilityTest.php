<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDeduplicationLock;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaMultipartLock;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaTransactionRollbackRegistry;

/**
 * Determine whether a competing process could acquire one media mutation lock.
 */
function mediaRollbackMutationLockIsAvailable(string $mediaId): bool
{
    $lockProvider = Cache::store('array')->getStore();

    if (! $lockProvider instanceof LockProvider) {
        throw new RuntimeException('The test cache store must support atomic locks.');
    }

    $lock = $lockProvider->lock(
        'nvl:media:mutation:'.hash('sha256', $mediaId),
        10,
    );

    if (! $lock->get()) {
        return false;
    }

    $lock->release();

    return true;
}

test('file rollback callbacks follow the root outcome without accumulating listeners', function (): void {
    Storage::fake('public');

    $originalConnection = DB::getDefaultConnection();
    $isolatedConnection = 'media-file-rollback-compatibility';
    config([
        "database.connections.{$isolatedConnection}" => config(
            "database.connections.{$originalConnection}",
        ),
    ]);
    DB::setDefaultConnection($isolatedConnection);

    try {
        $scheduler = app(MediaFileEffectScheduler::class);
        $rollbackCallbacks = app(MediaTransactionRollbackRegistry::class);
        $events = Event::getFacadeRoot();

        expect($events)->toBeInstanceOf(Dispatcher::class);

        if (! $events instanceof Dispatcher) {
            return;
        }

        $listenerCount = count($events->getListeners(TransactionRolledBack::class));
        $rolledBackPath = 'media/rollback-compatibility/rolled-back.txt';
        Storage::disk('public')->put($rolledBackPath, 'rollback');

        DB::beginTransaction();
        DB::beginTransaction();
        $scheduler->deleteAfterRollback(
            'public',
            [$rolledBackPath],
            'laravel_12_nested_rollback',
        );

        expect($rollbackCallbacks->count())->toBe(1);
        DB::rollBack();
        Storage::disk('public')->assertExists($rolledBackPath);
        expect($rollbackCallbacks->count())->toBe(1);
        DB::rollBack();
        Storage::disk('public')->assertMissing($rolledBackPath);
        expect($rollbackCallbacks->count())->toBe(0);

        $committedPath = 'media/rollback-compatibility/committed.txt';
        Storage::disk('public')->put($committedPath, 'commit');
        DB::beginTransaction();
        $scheduler->deleteAfterRollback(
            'public',
            [$committedPath],
            'laravel_12_commit',
        );
        DB::commit();

        Storage::disk('public')->assertExists($committedPath);
        expect($rollbackCallbacks->count())->toBe(0);

        DB::beginTransaction();
        DB::rollBack();

        Storage::disk('public')->assertExists($committedPath);
        expect($events->getListeners(TransactionRolledBack::class))
            ->toHaveCount($listenerCount);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::setDefaultConnection($originalConnection);
        DB::purge($isolatedConnection);
    }
});

test('owned media locks ignore and preserve colliding foreign lock keys', function (string $purpose, string $identity): void {
    config([
        "nvl-media.{$purpose}_lock.store" => 'array',
        "nvl-media.{$purpose}_lock.wait_seconds" => 0,
        'nvl-media.multipart.lock.store' => 'array',
        'nvl-media.multipart.lock.wait_seconds' => 0,
    ]);
    $foreignKey = "media:{$purpose}:".hash('sha256', $identity);
    $ownedKey = 'nvl:'.$foreignKey;
    $foreignLock = Cache::store('array')->lock($foreignKey, 30);
    expect($foreignLock->get())->toBeTrue();
    $callback = static function () use ($foreignKey, $ownedKey): Media {
        expect(Cache::store('array')->lock($ownedKey, 30)->get())->toBeFalse()
            ->and(Cache::store('array')->lock($foreignKey, 30)->get())->toBeFalse();

        return new Media;
    };

    try {
        $result = match ($purpose) {
            'mutation' => app(MediaMutationLock::class)->execute($identity, $callback),
            'multipart' => app(MediaMultipartLock::class)->execute($identity, $callback),
            'deduplication' => app(MediaDeduplicationLock::class)->execute('digest', 'public', true, null, null, $callback),
        };

        expect($result)->toBeInstanceOf(Media::class)
            ->and(Cache::store('array')->lock($foreignKey, 30)->get())->toBeFalse();
    } finally {
        $foreignLock->release();
    }
})->with([
    'mutation' => ['mutation', 'namespaced-media'],
    'multipart' => ['multipart', 'namespaced-session'],
    'deduplication' => ['deduplication', 'digest|public|public'],
]);

test('mutation locks survive savepoint rollback and release on either root outcome', function (): void {
    config([
        'nvl-media.mutation_lock.enabled' => true,
        'nvl-media.mutation_lock.store' => 'array',
        'nvl-media.mutation_lock.seconds' => 10,
        'nvl-media.mutation_lock.wait_seconds' => 0,
    ]);

    $originalConnection = DB::getDefaultConnection();
    $isolatedConnection = 'media-lock-rollback-compatibility';
    config([
        "database.connections.{$isolatedConnection}" => config(
            "database.connections.{$originalConnection}",
        ),
    ]);
    DB::setDefaultConnection($isolatedConnection);

    try {
        $lock = app(MediaMutationLock::class);
        $rollbackCallbacks = app(MediaTransactionRollbackRegistry::class);

        DB::beginTransaction();
        $lock->execute('rolled-back', static fn (): null => null);
        DB::beginTransaction();

        expect(mediaRollbackMutationLockIsAvailable('rolled-back'))->toBeFalse()
            ->and($rollbackCallbacks->count())->toBe(1);

        DB::rollBack();

        expect(mediaRollbackMutationLockIsAvailable('rolled-back'))->toBeFalse()
            ->and($rollbackCallbacks->count())->toBe(1);

        DB::rollBack();

        expect(mediaRollbackMutationLockIsAvailable('rolled-back'))->toBeTrue()
            ->and($rollbackCallbacks->count())->toBe(0);

        DB::beginTransaction();
        $lock->execute('committed', static fn (): null => null);

        expect(mediaRollbackMutationLockIsAvailable('committed'))->toBeFalse()
            ->and($rollbackCallbacks->count())->toBe(1);

        DB::commit();

        expect(mediaRollbackMutationLockIsAvailable('committed'))->toBeTrue()
            ->and($rollbackCallbacks->count())->toBe(0);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::setDefaultConnection($originalConnection);
        DB::purge($isolatedConnection);
    }
});
