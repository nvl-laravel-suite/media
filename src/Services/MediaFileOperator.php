<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCreateDirectory;
use Nvl\Media\Enums\MediaVisibility;
use Throwable;

/**
 * Performs media file writes, moves, copies, deletes, and local directory cleanup.
 *
 * The operator owns storage mutations only. Read metadata and URL behavior stay
 * in MediaDiskGateway, while existence caching stays in MediaFileExistence.
 */
final class MediaFileOperator
{
    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaFileExistence $existence,
    ) {}

    /**
     * Store an uploaded file or string payload under the target filename.
     *
     * @param  UploadedFile|string  $file  File upload or raw contents
     * @param  string  $disk  Target disk
     * @param  string  $folder  Target folder
     * @param  string  $filename  Target filename
     * @return bool|string Stored path or false when storage failed
     */
    public function store(
        UploadedFile|string $file,
        string $disk,
        string $folder,
        string $filename,
        MediaVisibility $visibility = MediaVisibility::Private,
    ): bool|string {
        $this->ensureDirectoryExists($disk, $folder);
        $options = $this->writeOptions($disk, $visibility);

        if ($file instanceof UploadedFile) {
            $result = $this->disk($disk)->putFileAs($folder, $file, $filename, $options);
            $this->existence->forget($disk, $this->joinPath($folder, $filename));

            return $result;
        }

        $path = $this->joinPath($folder, $filename);
        $stored = $this->disk($disk)->put($path, $file, $options);
        $this->existence->forget($disk, $path);

        return $stored ? $path : false;
    }

    /**
     * Store raw contents at the exact object path.
     *
     * @param  string  $disk  Target disk
     * @param  string  $path  Target object path
     * @param  string  $contents  Raw file contents
     * @return bool True when storage succeeded
     */
    public function put(
        string $disk,
        string $path,
        string $contents,
        MediaVisibility $visibility = MediaVisibility::Private,
    ): bool {
        $this->ensureDirectoryExists($disk, dirname($path));

        $stored = (bool) $this->disk($disk)->put($path, $contents, $this->writeOptions($disk, $visibility));
        $this->existence->forget($disk, $path);

        return $stored;
    }

    /**
     * Delete an object and clean its empty local parent directory when enabled.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Relative object path
     * @return bool True when Flysystem reports deletion success
     */
    public function delete(string $disk, string $path): bool
    {
        $result = $this->disk($disk)->delete($path);
        $this->existence->forget($disk, $path);

        if (config('media.clean_empty_directories', true)) {
            $this->cleanEmptyDirectory($disk, dirname($path));
        }

        return $result;
    }

    /**
     * Move an object within or across disks using stream copy for cross-disk moves.
     *
     * @param  string  $fromDisk  Source disk
     * @param  string  $fromPath  Source object path
     * @param  string  $toDisk  Destination disk
     * @param  string  $toPath  Destination object path
     * @return bool True when the object moved
     */
    public function move(string $fromDisk, string $fromPath, string $toDisk, string $toPath): bool
    {
        if ($fromDisk === $toDisk) {
            $this->ensureDirectoryExists($toDisk, dirname($toPath));
            $result = $this->disk($fromDisk)->move($fromPath, $toPath);
            $this->existence->forget($fromDisk, $fromPath);
            $this->existence->forget($toDisk, $toPath);
            $this->cleanEmptyDirectory($fromDisk, dirname($fromPath));

            return $result;
        }

        $destinationExisted = $this->existence->exists($toDisk, $toPath);

        if ($destinationExisted) {
            if (! $this->objectsMatch($fromDisk, $fromPath, $toDisk, $toPath)) {
                return false;
            }
        } else {
            try {
                if (! $this->copy($fromDisk, $fromPath, $toDisk, $toPath)) {
                    $this->discardCreatedObject($toDisk, $toPath);

                    return false;
                }

                if (! $this->objectsMatch($fromDisk, $fromPath, $toDisk, $toPath)) {
                    $this->discardCreatedObject($toDisk, $toPath);

                    return false;
                }
            } catch (Throwable $exception) {
                $this->discardCreatedObject($toDisk, $toPath);

                throw $exception;
            }
        }

        try {
            $deleted = $this->delete($fromDisk, $fromPath);
        } catch (Throwable $exception) {
            if (! $destinationExisted) {
                $this->discardCreatedObject($toDisk, $toPath);
            }

            throw $exception;
        }

        if (! $deleted && ! $destinationExisted) {
            $this->discardCreatedObject($toDisk, $toPath);
        }

        return $deleted;
    }

    /**
     * Copy an object between disks through a stream.
     *
     * @param  string  $fromDisk  Source disk
     * @param  string  $fromPath  Source object path
     * @param  string  $toDisk  Destination disk
     * @param  string  $toPath  Destination object path
     * @return bool True when the object copied
     */
    public function copy(string $fromDisk, string $fromPath, string $toDisk, string $toPath): bool
    {
        $stream = $this->disks->readStream($fromDisk, $fromPath);

        if (! is_resource($stream)) {
            return false;
        }

        try {
            $this->ensureDirectoryExists($toDisk, dirname($toPath));
            $result = $this->disk($toDisk)->put(
                $toPath,
                $stream,
                $this->writeOptions($toDisk, $this->disks->visibility($fromDisk, $fromPath)),
            );
            $this->existence->forget($toDisk, $toPath);

            return (bool) $result;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Delete a directory on disks that support the operation.
     *
     * @param  string  $disk  Disk name
     * @param  string  $directory  Relative directory path
     * @return bool True when deletion succeeded
     */
    public function deleteDirectory(string $disk, string $directory): bool
    {
        return $this->disk($disk)->deleteDirectory($directory);
    }

    /**
     * Create a directory when the target disk needs explicit directories.
     *
     * @param  string  $disk  Disk name
     * @param  string  $directory  Relative directory path
     */
    public function ensureDirectoryExists(string $disk, string $directory): void
    {
        $directory = trim($directory, '/');

        if ($directory === '' || $directory === '.' || $this->disks->isS3($disk)) {
            return;
        }

        if ($this->disk($disk)->exists($directory)) {
            return;
        }

        try {
            $this->disk($disk)->makeDirectory($directory);
            $this->existence->forget($disk, $directory);
        } catch (UnableToCreateDirectory $exception) {
            if (! $this->disks->isLocal($disk)) {
                Log::error('MediaFileOperator failed to create remote directory.', [
                    'disk' => $disk,
                    'directory' => $directory,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $absolutePath = $this->disks->localPath($disk, $directory);

            if (! is_dir($absolutePath) && ! @mkdir($absolutePath, 0755, true) && ! is_dir($absolutePath)) {
                Log::error('MediaFileOperator failed to create local directory.', [
                    'disk' => $disk,
                    'directory' => $directory,
                    'absolute_path' => $absolutePath,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        }
    }

    /**
     * Resolve a filesystem adapter for internal write operations.
     *
     * @param  string  $disk  Disk name
     * @return FilesystemAdapter Filesystem adapter
     */
    private function disk(string $disk): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk($disk);
    }

    /**
     * Determine whether two objects have identical byte size and SHA-256 checksum.
     *
     * @param  string  $firstDisk  First disk name
     * @param  string  $firstPath  First object path
     * @param  string  $secondDisk  Second disk name
     * @param  string  $secondPath  Second object path
     * @return bool True when both objects contain the same bytes
     */
    private function objectsMatch(
        string $firstDisk,
        string $firstPath,
        string $secondDisk,
        string $secondPath,
    ): bool {
        if ($this->disks->size($firstDisk, $firstPath) !== $this->disks->size($secondDisk, $secondPath)) {
            return false;
        }

        return hash_equals(
            $this->disks->checksum($firstDisk, $firstPath),
            $this->disks->checksum($secondDisk, $secondPath),
        );
    }

    /**
     * Remove an object created by a failed copy without masking the original failure.
     *
     * @param  string  $disk  Disk name
     * @param  string  $path  Object path
     */
    private function discardCreatedObject(string $disk, string $path): void
    {
        try {
            if ($this->existence->exists($disk, $path) && ! $this->delete($disk, $path)) {
                Log::warning('MediaFileOperator could not remove an unverified destination object.', [
                    'disk' => $disk,
                    'path' => $path,
                ]);
            }
        } catch (Throwable $cleanupError) {
            Log::warning('MediaFileOperator could not remove an unverified destination object.', [
                'disk' => $disk,
                'path' => $path,
                'error' => $cleanupError->getMessage(),
            ]);
        }
    }

    /**
     * Build safe Flysystem write options for local and object-storage disks.
     *
     * @return array<string, string>
     */
    private function writeOptions(string $disk, MediaVisibility $visibility): array
    {
        if ($this->disks->isS3($disk) && config('media.s3.use_acl_visibility', false) !== true) {
            return [];
        }

        return ['visibility' => $visibility->value];
    }

    /**
     * Remove an empty local parent directory after object deletion or move.
     *
     * @param  string  $disk  Disk name
     * @param  string  $directory  Relative directory path
     */
    private function cleanEmptyDirectory(string $disk, string $directory): void
    {
        $directory = trim($directory, '/');

        if ($directory === '' || $directory === '.' || ! $this->disks->isLocal($disk)) {
            return;
        }

        if ($this->disk($disk)->exists($directory) && empty($this->disk($disk)->allFiles($directory))) {
            $this->disk($disk)->deleteDirectory($directory);
        }
    }

    /**
     * Join a folder and filename into a normalized object path.
     *
     * @param  string  $folder  Folder path
     * @param  string  $filename  File name
     * @return string Relative object path
     */
    private function joinPath(string $folder, string $filename): string
    {
        $folder = trim($folder, '/');

        return $folder !== '' ? $folder.'/'.$filename : $filename;
    }
}
