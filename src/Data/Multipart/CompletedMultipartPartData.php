<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Multipart;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Provider receipt for one uploaded part.
 */
#[TypeScript]
final class CompletedMultipartPartData extends Data
{
    /**
     * Create a completed part receipt.
     */
    public function __construct(
        public readonly int $partNumber,
        public readonly string $etag,
        public readonly ?string $checksum = null,
    ) {}
}
