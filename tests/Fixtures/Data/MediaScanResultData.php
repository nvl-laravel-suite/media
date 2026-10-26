<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data;

use Spatie\LaravelData\Data;

/**
 * Scanner-attested technical metadata for a direct upload.
 *
 * @api
 */
final class MediaScanResultData extends Data
{
    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public readonly bool $clean,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $size,
        public readonly string $checksum,
        public readonly array $diagnostics = [],
    ) {}
}
