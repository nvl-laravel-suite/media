<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Multipart;

use DateTimeImmutable;
use Nvl\Media\Enums\MediaVisibility;
use Spatie\LaravelData\Data;

/**
 * Opaque server-issued multipart upload session.
 *
 * @api
 */
final class MultipartUploadSessionData extends Data
{
    /**
     * Create a multipart session.
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly string $disk,
        public readonly string $objectKey,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $checksum,
        public readonly MediaVisibility $visibility,
        public readonly int|string|null $uploaderId,
        public readonly ?string $uploaderType,

        public readonly DateTimeImmutable $expiresAt,
        public readonly int $minimumPartSize,
        public readonly int $maximumParts,
    ) {}
}
