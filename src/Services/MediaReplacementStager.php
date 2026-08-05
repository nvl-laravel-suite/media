<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Data\Ingestion\StagedMediaFileData;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaHashGenerator;
use RuntimeException;
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
    ) {}

    /**
     * Stage one replacement file on the media record's current disk.
     *
     * @throws MediaUploadException
     * @throws RuntimeException
     */
    public function stage(Media $media, UploadedFile $file): StagedMediaFileData
    {
        $this->diskGuard->assertAllowed($media->disk);
        $this->disks->ensureDefined($media->disk);
        $media->loadMissing('associations.associable');
        $slots = [];

        foreach ($media->associations as $association) {
            $associable = $association->associable;

            if (! $associable instanceof HasMedia) {
                continue;
            }

            $configuredSlot = data_get($association->metadata, 'slot');
            $slotName = is_string($configuredSlot) && $configuredSlot !== ''
                ? $configuredSlot
                : $association->collection;
            $slot = $associable->getMediaSlot($slotName);

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
        $storageFolder = Media::storagePath($media->folder ?? '');
        $stored = $this->files->store(
            $file,
            $media->disk,
            $storageFolder,
            $hash,
            $media->visibility,
        );

        if ($stored === false) {
            throw new RuntimeException("Failed to store replacement file for media [{$media->id}].");
        }

        $path = is_string($stored)
            ? $stored
            : ($storageFolder !== '' ? $storageFolder.'/'.$hash : $hash);

        try {
            $storedDigest = $this->disks->checksum($media->disk, $path);
            $storedSize = $this->disks->size($media->disk, $path);

            if (! hash_equals($validatedFile->digest, $storedDigest)
                || $storedSize !== $validatedFile->size) {
                Log::error('Media replacement integrity verification failed.', [
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $path,
                    'expected_checksum' => $validatedFile->digest,
                    'actual_checksum' => $storedDigest,
                    'expected_size' => $validatedFile->size,
                    'actual_size' => $storedSize,
                ]);

                throw new MediaUploadException(
                    "Integrity verification failed for replacement media [{$media->id}].",
                );
            }
        } catch (Throwable $exception) {
            try {
                if (! $this->files->delete($media->disk, $path)) {
                    Log::warning('Media replacement cleanup reported failure.', [
                        'media_id' => $media->id,
                        'disk' => $media->disk,
                        'path' => $path,
                    ]);
                }
            } catch (Throwable $cleanupException) {
                Log::error('Media replacement cleanup threw an exception.', [
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $path,
                    'exception' => $cleanupException::class,
                    'error' => $cleanupException->getMessage(),
                ]);
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
