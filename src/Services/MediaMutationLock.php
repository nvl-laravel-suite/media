<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Support\Config\PackageOptions;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Throwable;

/**
 * Serializes storage and database mutations for one or more media identities.
 *
 * @internal
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
        private readonly OwnershipBoundary $tenantBoundary,
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
        if (! (bool) config('nvl-media.mutation_lock.enabled', true)) {
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

        // Inside a Media transaction the locks outlive this call: they are released when its root commits or rolls back.
        $releasedByTransaction = $this->rollbackCallbacks->afterRootTransaction(function () use ($locks, $keys): void {
            $this->releaseLocks($locks, $keys);
        });

        try {
            return $callback();
        } finally {
            if (! $releasedByTransaction) {
                $this->releaseLocks($locks, $keys);
            }
        }
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
        return Cache::store(PackageOptions::lockStore('media', 'mutation'));
    }

    /**
     * Build a stable mutation lock key.
     */
    private function lockKey(string $mediaId): string
    {
        return 'nvl:media:mutation:'.hash('sha256', $this->tenantBoundary->key('media.assets', $mediaId));
    }

    /**
     * Resolve the lock lease length in seconds.
     */
    private function seconds(): int
    {
        return MediaConfiguration::integer('nvl-media.mutation_lock.seconds', 300, 1);
    }

    /**
     * Resolve the maximum lock wait in seconds.
     */
    private function waitSeconds(): int
    {
        return MediaConfiguration::integer('nvl-media.mutation_lock.wait_seconds', 30);
    }

    /**
     * Release acquired locks in reverse order.
     *
     * @param  list<Lock>  $locks
     * @param  list<string>  $keys
     */
    private function releaseLocks(array $locks, array $keys): void
    {
        foreach (array_reverse($locks) as $lock) {
            $lock->release();
        }

        foreach ($keys as $key) {
            unset($this->heldKeys[$key]);
        }
    }
}
