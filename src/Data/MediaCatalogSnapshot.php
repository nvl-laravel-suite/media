<?php

declare(strict_types=1);

namespace Nvl\Media\Data;

/** Scalar-only authorized platform asset snapshot. */
final readonly class MediaCatalogSnapshot
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $tags
     * @param  array<string, array<string, string|null>>  $translations
     */
    public function __construct(
        public string $grantId,
        public int $grantRevision,
        public string $sourceId,
        public int $sourceRevision,
        public string $sourceDigest,
        public string $disk,
        public string $storagePath,
        public string $filename,
        public string $extension,
        public string $mimeType,
        public int $size,
        public array $metadata,
        public array $tags,
        public array $translations,
    ) {}
}
