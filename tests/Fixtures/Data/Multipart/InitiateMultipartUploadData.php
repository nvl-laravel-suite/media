<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Multipart;

use Nvl\Media\Enums\MediaVisibility;
use Spatie\LaravelData\Data;

/**
 * Validated metadata for a direct multipart upload.
 *
 * @api
 */
final class InitiateMultipartUploadData extends Data
{
    /**
     * Create multipart initiation data.
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $checksum,
        public readonly MediaVisibility $visibility = MediaVisibility::Private,
        public readonly ?string $folder = null,
    ) {}
}
