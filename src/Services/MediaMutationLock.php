<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Support\MediaConfiguration;
use Throwable;

/**
 * Serializes storage and database mutations for one or more media identities.
 */
final class MediaMutationLock
{
    /** @var array<string, true> */
    private array $heldKeys = [];

    /**
     * Create the media mutation lock coordinator.
     */
    public function __construct(
        private readonly MediaTransactionRollbackRegistry $rollbackCallbacks,
    ) {}

    /**
     * Execute a media mutation while holding its distributed lock.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws MediaUploadException
     */
    public function execute(string $mediaId, Closure $callback): mixed
    {
        return $this->executeMany([$mediaId], $callback);
    }

    /**
     * Serialize one owner collection while a size-limited slot is replaced.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws MediaUploadException
     */
    public function executeForOwnerCollection(
        Model $owner,
        string $collection,
        Closure $callback,
    ): mixed {
        $ownerId = $owner->getKey();

        if (! is_int($ownerId) && ! is_string($ownerId)) {
            throw new MediaUploadException(
                'A persisted media owner is required for collection-scoped mutations.',
            );
        }

        $scope = hash('sha256', implode("\0", [
            $owner->getMorphClass(),
            (string) $ownerId,
            $collection,
        ]));

        return $this->execute("owner-collection:{$scope}", $callback);
    }

    /**
     * Execute a bulk mutation while holding sorted locks for every media identity.
     *
     * @template TResult
     *
     * @param  list<string>  $mediaIds
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws MediaUploadException
     */
    public function executeMany(array $mediaIds, Closure $callback): mixed
    {
        if (! (bool) config('media.mutation_lock.enabled', true)) {
            return $callback();
        }

        $mediaIds = array_values(array_unique($mediaIds));
        sort($mediaIds);
        $mediaIds = array_values(array_filter(
            $mediaIds,
            fn (string $mediaId): bool => ! isset($this->heldKeys[$this->lockKey($mediaId)]),
        ));

        if ($mediaIds === []) {
            return $callback();
        }

        $locks = [];
        $keys = [];

        try {
            foreach ($mediaIds as $mediaId) {
                $key = $this->lockKey($mediaId);
                $lock = $this->lockProvider()->lock($key, $this->seconds());
                $lock->block($this->waitSeconds());
                $locks[] = $lock;
                $keys[] = $key;
                $this->heldKeys[$key] = true;
            }
        } catch (LockTimeoutException $exception) {
            $this->releaseLocks($locks, $keys);

            throw new MediaUploadException(
                'Timed out while waiting for a media mutation lock.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            $this->releaseLocks($locks, $keys);

            throw $exception;
        }

        $rootTransaction = $this->rootTransaction();

        if ($rootTransaction instanceof DatabaseTransactionRecord) {
            $rollbackRegistrationId = $this->rollbackCallbacks->register(
                connection: $rootTransaction->connection,
                rootLevel: $rootTransaction->level,
                callback: function () use ($locks, $keys): void {
                    $this->releaseLocks($locks, $keys);
                },
            );
            $rootTransaction->addCallback(function () use (
                $rollbackRegistrationId,
                $locks,
                $keys,
            ): bool {
                $this->rollbackCallbacks->cancel($rollbackRegistrationId);

                return $this->releaseLocks($locks, $keys);
            });
        }

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            if (! $rootTransaction instanceof DatabaseTransactionRecord) {
                $this->releaseLocks($locks, $keys);
            }

            throw $exception;
        }

        if (! $rootTransaction instanceof DatabaseTransactionRecord) {
            $this->releaseLocks($locks, $keys);
        }

        return $result;
    }

    /**
     * Resolve the cache store that provides atomic locks.
     *
     * @throws MediaUploadException
     */
    private function lockProvider(): LockProvider
    {
        $store = $this->repository()->getStore();

        if (! $store instanceof LockProvider) {
            throw new MediaUploadException(
                'The configured media mutation cache store does not support atomic locks.',
            );
        }

        return $store;
    }

    /**
     * Resolve the configured mutation-lock cache repository.
     */
    private function repository(): Repository
    {
        $store = config('media.mutation_lock.store');

        if (is_string($store) && $store !== '') {
            return Cache::store($store);
        }

        return Cache::store();
    }

    /**
     * Build a stable mutation lock key.
     */
    private function lockKey(string $mediaId): string
    {
        return 'media:mutation:'.hash('sha256', $mediaId);
    }

    /**
     * Resolve the lock lease length in seconds.
     */
    private function seconds(): int
    {
        return MediaConfiguration::integer('media.mutation_lock.seconds', 300, 1);
    }

    /**
     * Resolve the maximum lock wait in seconds.
     */
    private function waitSeconds(): int
    {
        return MediaConfiguration::integer('media.mutation_lock.wait_seconds', 30);
    }

    /**
     * Find the real root transaction record that owns lifecycle callbacks.
     */
    private function rootTransaction(): ?DatabaseTransactionRecord
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() === 0) {
            return null;
        }

        $transactions = DB::getFacadeApplication()?->make('db.transactions');

        if (! $transactions instanceof DatabaseTransactionsManager) {
            return null;
        }

        $root = $transactions
            ->callbackApplicableTransactions()
            ->filter(
                static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection->getName(),
            )
            ->sortBy('level')
            ->first();

        return $root instanceof DatabaseTransactionRecord ? $root : null;
    }

    /**
     * Release acquired locks in reverse order.
     *
     * @param  list<Lock>  $locks
     * @param  list<string>  $keys
     */
    private function releaseLocks(array $locks, array $keys): bool
    {
        foreach (array_reverse($locks) as $lock) {
            $lock->release();
        }

        foreach ($keys as $key) {
            unset($this->heldKeys[$key]);
        }

        return true;
    }
}
