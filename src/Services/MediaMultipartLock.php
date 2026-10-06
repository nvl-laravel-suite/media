<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Support\Config\PackageOptions;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;

/**
 * Serializes completion and abortion transitions for one multipart session.
 */
final class MediaMultipartLock
{
    public function __construct(private readonly TenantBoundary $tenantBoundary) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function execute(string $sessionId, Closure $callback): mixed
    {
        $repository = Cache::store(PackageOptions::lockStore('media', 'multipart'));
        $provider = $repository->getStore();

        if (! $provider instanceof LockProvider) {
            throw new MediaUploadException(
                'The configured multipart cache store does not support atomic locks.',
            );
        }

        $lock = $provider->lock(
            'nvl:media:multipart:'.hash('sha256', $this->tenantBoundary->key('media.multipart', $sessionId)),
            MediaConfiguration::integer('nvl-media.multipart.lock.seconds', 300, 1),
        );

        try {
            return $lock->block(
                MediaConfiguration::integer('nvl-media.multipart.lock.wait_seconds', 30),
                $callback,
            );
        } catch (LockTimeoutException $exception) {
            throw new MediaUploadException(
                'Timed out while waiting for the multipart session lock.',
                previous: $exception,
            );
        }
    }
}
