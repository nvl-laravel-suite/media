<?php

declare(strict_types=1);

namespace Nvl\Media\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Scanner-attested technical metadata for a direct upload.
 */
#[TypeScript]
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
