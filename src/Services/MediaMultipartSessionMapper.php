<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\MediaMultipartUpload;

/**
 * Builds an opaque multipart session array from persisted authoritative state.
 *
 * @internal
 */
final class MediaMultipartSessionMapper
{
    /** @return array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} */
    public function toData(MediaMultipartUpload $session): array
    {
        $expiresAt = $session->expires_at->toDateTimeImmutable();

        return ['uploadId' => $session->id, 'disk' => $session->disk, 'objectKey' => $session->object_key, 'filename' => $session->display_filename, 'mimeType' => $session->declared_mime, 'size' => $session->expected_size, 'checksum' => $session->expected_checksum, 'visibility' => $session->visibility, 'uploaderId' => $session->uploader_id, 'uploaderType' => $session->uploader_type, 'expiresAt' => $expiresAt, 'minimumPartSize' => $session->minimum_part_size, 'maximumParts' => $session->maximum_parts];
    }
}
