<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Support\MediaTemporaryLocalFile;
use RuntimeException;
use Throwable;

/**
 * Materializes media objects to local filesystem paths for local-only processors.
 */
final class MediaLocalFileMaterializer
{
    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaTemporaryFileRegistry $temporaryFiles,
    ) {}

    /**
     * Resolve a local path for a media object, downloading remote objects to a tracked temp file.
     *
     * @param  string  $disk  Source disk
     * @param  string  $path  Source object path
     * @return string Absolute local path
     */
    public function materialize(string $disk, string $path): string
    {
        if ($this->disks->isLocal($disk)) {
            return $this->disks->localPath($disk, $path);
        }

        return $this->downloadToTemp($disk, $path, 'media_mat_');
    }

    /**
     * Acquire an explicit local-file lease for short-lived processing.
     *
     * @param  string  $disk  Source disk
     * @param  string  $path  Source object path
     * @return MediaTemporaryLocalFile Temporary local-file lease
     */
    public function lease(string $disk, string $path): MediaTemporaryLocalFile
    {
        if ($this->disks->isLocal($disk)) {
            return new MediaTemporaryLocalFile($this->disks->localPath($disk, $path));
        }

        $temporaryPath = $this->downloadToTemp($disk, $path, 'media_src_');

        return new MediaTemporaryLocalFile($temporaryPath, $this->temporaryFiles);
    }

    /**
     * Download a remote object to a temporary local file.
     *
     * @param  string  $disk  Source disk
     * @param  string  $path  Source object path
     * @param  string  $prefix  Temporary filename prefix
     * @return string Absolute temporary path
     */
    private function downloadToTemp(string $disk, string $path, string $prefix): string
    {
        $stream = $this->disks->readStream($disk, $path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to open media stream for [{$disk}:{$path}].");
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), $prefix);

        if ($temporaryPath === false) {
            fclose($stream);

            throw new RuntimeException("Unable to create temporary file for [{$disk}:{$path}].");
        }

        $this->temporaryFiles->track($temporaryPath);
        $target = fopen($temporaryPath, 'wb');

        if ($target === false) {
            fclose($stream);
            $this->temporaryFiles->release($temporaryPath);

            throw new RuntimeException("Unable to open temporary file for [{$disk}:{$path}].");
        }

        try {
            try {
                if (stream_copy_to_stream($stream, $target) === false) {
                    throw new RuntimeException("Unable to download media stream for [{$disk}:{$path}].");
                }
            } finally {
                fclose($stream);
                fclose($target);
            }
        } catch (Throwable $exception) {
            $this->temporaryFiles->release($temporaryPath);

            throw $exception;
        }

        return $this->appendExtension($temporaryPath, $path);
    }

    /**
     * Append the source extension to temp paths for processors that inspect filenames.
     *
     * @param  string  $temporaryPath  Temporary file path
     * @param  string  $sourcePath  Source object path
     * @return string Temporary path with extension when rename succeeds
     */
    private function appendExtension(string $temporaryPath, string $sourcePath): string
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        if ($extension === '') {
            return $temporaryPath;
        }

        $extendedTemporaryPath = $temporaryPath.'.'.$extension;

        if (rename($temporaryPath, $extendedTemporaryPath)) {
            $this->temporaryFiles->release($temporaryPath);
            $this->temporaryFiles->track($extendedTemporaryPath);

            return $extendedTemporaryPath;
        }

        return $temporaryPath;
    }
}
