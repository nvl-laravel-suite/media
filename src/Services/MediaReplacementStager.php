<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Data\Ingestion\StagedMediaFileData;
use Nvl\Media\Exceptions\MediaStorageException;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaHashGenerator;
use Nvl\Support\Logging\PackageLogger;
use Throwable;

/**
 * Validates, scans, stores, and verifies a replacement object before database mutation.
 */
final class MediaReplacementStager
{
    /**
     * Create the replacement stager.
     */
    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaDiskGuard $diskGuard,
        private readonly MediaFileOperator $files,
        private readonly MediaIngestionPipeline $ingestion,
        private readonly MediaPathResolver $pathResolver,
        private PackageLogger $packageLogger,
    ) {}

    /**
     * Stage one replacement file on the media record's current disk.
     *
     * @throws MediaUploadException
     * @throws MediaStorageException
     */
    public function stage(Media $media, UploadedFile $file): StagedMediaFileData
    {
        $this->diskGuard->assertAllowed($media->disk);
        $this->disks->ensureDefined($media->disk);
        $media->loadMissing('associations.associable');
        $slots = [];

        foreach ($media->associations as $association) {
            $associable = $association->associable;

            if (! $associable instanceof HasNvlMedia) {
                continue;
            }

            $configuredSlot = data_get($association->metadata, 'slot');
            $slotName = is_string($configuredSlot) && $configuredSlot !== ''
                ? $configuredSlot
                : $association->collection;
            $slot = $associable->nvlMedia()->slot($slotName);

            if ($slot instanceof MediaSlot) {
                $slots[] = $slot;
            }
        }

        $validatedFile = $this->ingestion->inspect(
            $file,
            $slots !== [] ? $slots : [new MediaSlot('replacement')],
            $file->getClientOriginalName(),
        );

        $hash = MediaHashGenerator::generateForExtension($validatedFile->extension);
        $storageFolder = $this->pathResolver->storageFolder($media->folder ?? '');
        $stored = $this->files->store(
            $file,
            $media->disk,
            $storageFolder,
            $hash,
            $media->visibility,
        );

        if ($stored === false) {
            throw new MediaStorageException("Failed to store replacement file for media [{$media->id}].");
        }

        $path = is_string($stored)
            ? $stored
            : ($storageFolder !== '' ? $storageFolder.'/'.$hash : $hash);

        try {
            $storedDigest = $this->disks->checksum($media->disk, $path);
            $storedSize = $this->disks->size($media->disk, $path);

            if (! hash_equals($validatedFile->digest, $storedDigest)
                || $storedSize !== $validatedFile->size) {
                $this->packageLogger->log('media', 'error', 'nvl.media.replacement_integrity.failed', [
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $path,
                    'expected_checksum' => $validatedFile->digest,
                    'actual_checksum' => $storedDigest,
                    'expected_size' => $validatedFile->size,
                    'actual_size' => $storedSize,
                ], 'normal');

                throw new MediaUploadException(
                    "Integrity verification failed for replacement media [{$media->id}].",
                );
            }
        } catch (Throwable $exception) {
            try {
                if (! $this->files->delete($media->disk, $path)) {
                    $this->packageLogger->log('media', 'warning', 'nvl.media.replacement_cleanup.rejected', [
                        'media_id' => $media->id,
                        'disk' => $media->disk,
                        'path' => $path,
                    ], 'normal');
                }
            } catch (Throwable $cleanupException) {
                $this->packageLogger->log('media', 'error', 'nvl.media.replacement_cleanup.failed', [
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $path,
                    'exception' => $cleanupException::class,
                    'error' => $cleanupException->getMessage(),
                ], 'normal');
            }

            throw $exception;
        }

        return new StagedMediaFileData(
            validatedFile: $validatedFile,
            hash: $hash,
            path: $path,
        );
    }
}
