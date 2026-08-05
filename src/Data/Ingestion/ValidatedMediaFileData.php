<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Ingestion;

use Nvl\Media\Enums\MediaType;
use Spatie\LaravelData\Data;

/**
 * Authoritative technical metadata for a validated local media source.
 */
final class ValidatedMediaFileData extends Data
{
    /**
     * Create validated media-file metadata.
     */
    public function __construct(
        public readonly string $displayFilename,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $digest,
        public readonly MediaType $type,
        public readonly string $realPath,
    ) {}
}
