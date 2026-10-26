<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaConfiguration;

/**
 * Enforces package-wide and slot-specific upload constraints at the action boundary.
 *
 * @internal
 */
final class MediaUploadValidator
{
    /**
     * Create the upload validator.
     */
    public function __construct(
        private readonly MediaFileTypePolicy $fileTypePolicy,
    ) {}

    /**
     * Validate a materialized upload before any filesystem or database mutation.
     *
     * @return array{displayFilename: string, extension: string, mimeType: string, size: int, digest: string, type: MediaType, realPath: string}
     *
     * @throws FileUnacceptableForCollection
     * @throws MediaUploadException
     */
    public function validate(
        UploadedFile $file,
        MediaSlot $slot,
        ?string $displayFilename = null,
    ): array {
        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            throw new MediaUploadException('Unable to resolve the uploaded media file.');
        }

        $detectedMimeType = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);

        if (! is_string($detectedMimeType) || $detectedMimeType === '') {
            throw new FileUnacceptableForCollection('Unable to detect the uploaded media MIME type.');
        }

        $fileType = $this->fileTypePolicy->resolve(
            $displayFilename ?? $file->getClientOriginalName(),
            $detectedMimeType,
        );

        $size = filesize($realPath);

        if (! is_int($size) || $size < 1) {
            throw new FileUnacceptableForCollection(
                "Cannot upload a zero-byte file [{$fileType['displayFilename']}].",
            );
        }

        $digest = hash_file('sha256', $realPath);

        if (! is_string($digest)) {
            throw new MediaUploadException(
                "Unable to calculate a checksum for [{$fileType['displayFilename']}].",
            );
        }

        $validatedFile = ['displayFilename' => $fileType['displayFilename'], 'extension' => $fileType['extension'], 'mimeType' => $fileType['mimeType'], 'size' => $size, 'digest' => $digest, 'type' => $fileType['type'], 'realPath' => $realPath];
        $this->assertAcceptedBySlot($validatedFile, $file, $slot);

        return $validatedFile;
    }

    /**
     * Apply one slot's policy to already normalized technical metadata.
     *
     * @param  array{displayFilename: string, extension: string, mimeType: string, size: int, digest: string, type: MediaType, realPath: string}  $validatedFile
     *
     * @throws FileUnacceptableForCollection
     */
    public function assertAcceptedBySlot(
        array $validatedFile,
        UploadedFile $file,
        MediaSlot $slot,
    ): void {
        if ($slot->acceptedMimeTypes !== []
            && ! in_array($validatedFile['mimeType'], $slot->acceptedMimeTypes, true)) {
            throw new FileUnacceptableForCollection(
                "File MIME type [{$validatedFile['mimeType']}] is not accepted by slot [{$slot->name}]. ".
                'Accepted: '.implode(', ', $slot->acceptedMimeTypes),
            );
        }

        $globalLimit = MediaConfiguration::integer(
            'nvl-media.max_file_size',
            10 * 1024 * 1024,
            1,
        );
        $limit = $slot->maxFileSize === null
            ? $globalLimit
            : min($globalLimit, $slot->maxFileSize);

        if ($validatedFile['size'] > $limit) {
            throw new FileUnacceptableForCollection(
                "File size [{$validatedFile['size']}] exceeds maximum [{$limit}] for slot [{$slot->name}].",
            );
        }

        if ($slot->fileAcceptor !== null && ! ($slot->fileAcceptor)($file)) {
            throw new FileUnacceptableForCollection(
                "File was rejected by the custom validator for slot [{$slot->name}].",
            );
        }
    }
}
