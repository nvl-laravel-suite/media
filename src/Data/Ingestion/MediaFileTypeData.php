<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Ingestion;

use Nvl\Media\Enums\MediaType;
use Spatie\LaravelData\Data;

/**
 * Canonical filename and content-type metadata resolved for one media source.
 */
final class MediaFileTypeData extends Data
{
    /**
     * Create canonical file-type metadata.
     */
    public function __construct(
        public readonly string $displayFilename,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly MediaType $type,
    ) {}
}
