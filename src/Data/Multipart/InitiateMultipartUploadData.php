<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Multipart;

use Nvl\Media\Enums\MediaVisibility;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Validated metadata for a direct multipart upload.
 */
#[TypeScript]
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
