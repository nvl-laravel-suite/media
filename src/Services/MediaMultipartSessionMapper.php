<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Models\MediaMultipartUpload;

/**
 * Builds an opaque multipart session DTO from persisted authoritative state.
 */
final class MediaMultipartSessionMapper
{
    public function toData(MediaMultipartUpload $session): MultipartUploadSessionData
    {
        $expiresAt = $session->expires_at->toDateTimeImmutable();

        return new MultipartUploadSessionData(
            uploadId: $session->id,
            disk: $session->disk,
            objectKey: $session->object_key,
            filename: $session->display_filename,
            mimeType: $session->declared_mime,
            size: $session->expected_size,
            checksum: $session->expected_checksum,
            visibility: $session->visibility,
            uploaderId: $session->uploader_id,
            uploaderType: $session->uploader_type,
            expiresAt: $expiresAt,
            minimumPartSize: $session->minimum_part_size,
            maximumParts: $session->maximum_parts,
        );
    }
}
