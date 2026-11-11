<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Support\Config\PackageOptions;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * MediaDeduplicationLock serializes shared digest uploads before file storage.
 *
 * @internal
 */
final class MediaDeduplicationLock
{
    /** @var array<string, true> Keys this request already holds until its open transaction ends */
    private array $heldByTransaction = [];

    public function __construct(
        private readonly OwnershipBoundary $tenantBoundary,
        private readonly MediaTransactionRollbackRegistry $transactions,
    ) {}

    /**
     * Execute a deduplicated upload workflow inside a scoped cache lock.
     *
     * @param  string  $digest  SHA-256 file digest
     * @param  string  $disk  Target storage disk
     * @param  bool  $isPublic  Visibility flag
     * @param  string|null  $uploadedBy  Uploader id for private media scope
     * @param  string|null  $uploadedByType  Uploader morph type for private media scope
     * @param  Closure(): Media  $callback  Upload workflow
     * @return Media Resulting media record
     *
     * @throws MediaUploadException When the lock cannot be acquired in time
     */
    public function execute(
        string $digest,
        string $disk,
        bool $isPublic,
        ?string $uploadedBy,
        ?string $uploadedByType,
        Closure $callback,
    ): Media {
        if (! (bool) config('nvl-media.deduplication_lock.enabled', true)) {
            return $callback();
        }

        $key = $this->lockKey($digest, $disk, $isPublic, $uploadedBy, $uploadedByType);

        if (isset($this->heldByTransaction[$key])) {
            return $callback();
        }

        $lock = $this->lockProvider()->lock($key, $this->seconds());

        try {
            $lock->block($this->waitSeconds());
        } catch (LockTimeoutException $exception) {
            throw new MediaUploadException('Timed out while waiting for a media deduplication lock.', previous: $exception);
        }

        // Inside a host transaction the new row stays invisible to other connections until the commit,
        // so the lock is held until that transaction ends; otherwise an identical upload would insert a second row.
        $releasedByTransaction = $this->transactions->afterRootTransaction(function () use ($lock, $key): void {
            unset($this->heldByTransaction[$key]);
            $lock->release();
        });

        if ($releasedByTransaction) {
            $this->heldByTransaction[$key] = true;
        }

        try {
            return $callback();
        } finally {
            if (! $releasedByTransaction) {
                $lock->release();
            }
        }
    }

    /**
     * Build a lock key scoped to digest, disk, visibility, and private owner.
     *
     * @param  string  $digest  SHA-256 file digest
     * @param  string  $disk  Target storage disk
     * @param  bool  $isPublic  Visibility flag
     * @param  string|null  $uploadedBy  Uploader id for private media scope
     * @param  string|null  $uploadedByType  Uploader morph type for private media scope
     * @return string Cache lock key
     */
    private function lockKey(
        string $digest,
        string $disk,
        bool $isPublic,
        ?string $uploadedBy,
        ?string $uploadedByType,
    ): string {
        $scope = $isPublic
            ? 'public'
            : 'private:'.($uploadedByType ?? 'untyped').':'.($uploadedBy ?? 'anonymous');

        return 'nvl:media:deduplication:'.hash('sha256', $this->tenantBoundary->key(
            'media.assets',
            implode('|', [$digest, $disk, $scope]),
        ));
    }

    /**
     * Resolve the configured lock cache provider.
     *
     * @return LockProvider Cache store with atomic lock support
     *
     * @throws MediaUploadException When the configured cache store does not support locks
     */
    private function lockProvider(): LockProvider
    {
        $store = $this->repository()->getStore();

        if (! $store instanceof LockProvider) {
            throw new MediaUploadException('The configured media deduplication cache store does not support atomic locks.');
        }

        return $store;
    }

    /**
     * Resolve the configured cache repository.
     *
     * @return Repository Cache repository
     */
    private function repository(): Repository
    {
        return Cache::store(PackageOptions::lockStore('media', 'deduplication'));
    }

    /**
     * Resolve lock lease length.
     *
     * @return int Seconds before lock auto-release
     */
    private function seconds(): int
    {
        return MediaConfiguration::integer('nvl-media.deduplication_lock.seconds', 30, 1);
    }

    /**
     * Resolve max wait time for a held lock.
     *
     * @return int Seconds to wait before failing
     */
    private function waitSeconds(): int
    {
        return MediaConfiguration::integer('nvl-media.deduplication_lock.wait_seconds', 30);
    }
}
