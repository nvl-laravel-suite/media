<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Support\MediaConfiguration;

/**
 * Caches and invalidates media object existence checks.
 *
 * The cache boundary is deliberately narrow so write services can invalidate
 * paths without owning the read behavior themselves.
 */
final class MediaFileExistence
{
    /**
     * Determine whether an object exists on the given disk.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @return bool True when the object exists
     */
    public function exists(string $disk, string $path): bool
    {
        if (! config('media.cache_file_existence', true)) {
            return $this->existsFresh($disk, $path);
        }

        $cacheKey = $this->cacheKey($disk, $path);
        $ttlSeconds = MediaConfiguration::integer('media.cache_ttl', 60, 1);

        return Cache::remember(
            $cacheKey,
            $ttlSeconds,
            fn (): bool => $this->existsFresh($disk, $path),
        );
    }

    /**
     * Determine whether an object currently exists without consulting cached state.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @return bool True when the object currently exists
     */
    public function existsFresh(string $disk, string $path): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Forget the cached existence value for a path.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     */
    public function forget(string $disk, string $path): void
    {
        Cache::forget($this->cacheKey($disk, $path));
    }

    /**
     * Build the cache key for a disk path.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @return string Cache key
     */
    private function cacheKey(string $disk, string $path): string
    {
        return "media_exists:{$disk}:{$path}";
    }
}
