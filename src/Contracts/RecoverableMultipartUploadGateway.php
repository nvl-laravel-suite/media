<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Enums\MediaVisibility;

/**
 * Multipart gateway capable of inspecting an object after an interrupted completion.
 *
 * @api
 */
interface RecoverableMultipartUploadGateway extends MultipartUploadGateway
{
    /** @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
     * @return array{path: string, checksum: string, size: int, mimeType: string, objectIdentity: string|null}|null
     */
    public function inspect(
        array $session,
    ): ?array;
}
