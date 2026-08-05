<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\DiskNotDefinedException;
use RuntimeException;
use Throwable;

/**
 * Resolves configured media disks and exposes read-only disk capabilities.
 *
 * This service centralizes disk checks, local path guards, URL creation, and
 * stream-safe reads so write operations can stay in MediaFileOperator.
 */
final class MediaDiskGateway
{
    /**
     * Assert that the named disk exists in filesystem configuration.
     *
     * @param  string  $disk  Disk name to validate
     * @return bool True when the disk is configured
     *
     * @throws DiskNotDefinedException When the disk is not configured
     */
    public function ensureDefined(string $disk): bool
    {
        if (config("filesystems.disks.{$disk}") === null) {
            throw new DiskNotDefinedException("The disk [{$disk}] is not defined in filesystems config.");
        }

        return true;
    }

    /**
     * Determine whether the disk uses Laravel's local driver.
     *
     * @param  string  $disk  Disk name to inspect
     * @return bool True when the disk is local
     */
    public function isLocal(string $disk): bool
    {
        return $this->driver($disk) === 'local';
    }

    /**
     * Determine whether the disk uses Laravel's S3-compatible driver.
     */
    public function isS3(string $disk): bool
    {
        return $this->driver($disk) === 's3';
    }

    /**
     * Resolve the configured Flysystem driver name.
     */
    public function driver(string $disk): ?string
    {
        $driver = config("filesystems.disks.{$disk}.driver");

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /**
     * Resolve object visibility, defaulting safely to private.
     */
    public function visibility(string $disk, string $path): MediaVisibility
    {
        try {
            return MediaVisibility::tryFrom(Storage::disk($disk)->visibility($path))
                ?? MediaVisibility::Private;
        } catch (Throwable) {
            return MediaVisibility::Private;
        }
    }

    /**
     * Resolve a local filesystem path for disks that support it.
     *
     * @param  string  $disk  Local disk name
     * @param  string  $path  Relative object path
     * @return string Absolute filesystem path
     */
    public function localPath(string $disk, string $path): string
    {
        if (! $this->isLocal($disk)) {
            throw new RuntimeException("Disk [{$disk}] does not support local path resolution.");
        }

        return Storage::disk($disk)->path($path);
    }

    /**
     * Build a direct disk URL for an object.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @return string Resolved disk URL
     */
    public function url(string $disk, string $path): string
    {
        return Storage::disk($disk)->url($path);
    }

    /**
     * Build a temporary disk URL for an object.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @param  DateTimeInterface  $expiration  Expiration timestamp
     * @return string Temporary URL
     */
    public function temporaryUrl(string $disk, string $path, DateTimeInterface $expiration): string
    {
        return Storage::disk($disk)->temporaryUrl($path, $expiration);
    }

    /**
     * Open a readable stream for an object.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @return resource|null Readable stream or null when unavailable
     */
    public function readStream(string $disk, string $path)
    {
        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (Throwable) {
            return null;
        }

        return is_resource($stream) ? $stream : null;
    }

    /**
     * Stream object paths and age metadata beneath one storage prefix.
     *
     * @return iterable<array{path: string, last_modified: int|null}>
     */
    public function objects(string $disk, string $prefix): iterable
    {
        $contents = Storage::disk($disk)
            ->getDriver()
            ->listContents(trim($prefix, '/'), true);

        foreach ($contents as $attributes) {
            if (! $attributes->isFile()) {
                continue;
            }

            yield [
                'path' => $attributes->path(),
                'last_modified' => $attributes->lastModified(),
            ];
        }
    }

    /**
     * Read an object's contents into memory.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @return string|null Object contents or null when unavailable
     */
    public function get(string $disk, string $path): ?string
    {
        $contents = Storage::disk($disk)->get($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Resolve an object's byte size.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @return int Object size in bytes
     */
    public function size(string $disk, string $path): int
    {
        return Storage::disk($disk)->size($path);
    }

    /**
     * Calculate a streamed checksum without loading the object into memory.
     */
    public function checksum(string $disk, string $path, string $algorithm = 'sha256'): string
    {
        if (! in_array($algorithm, hash_algos(), true)) {
            throw new RuntimeException("Unsupported checksum algorithm [{$algorithm}].");
        }

        $stream = $this->readStream($disk, $path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read [{$path}] from media disk [{$disk}].");
        }

        $context = hash_init($algorithm);

        try {
            hash_update_stream($context, $stream);
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    /**
     * Resolve an object's MIME type.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @return string MIME type with an octet-stream fallback
     */
    public function mimeType(string $disk, string $path): string
    {
        return Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';
    }
}
