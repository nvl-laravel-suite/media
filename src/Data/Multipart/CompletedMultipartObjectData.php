<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Multipart;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Server-verified metadata returned by object storage after completion.
 */
#[TypeScript]
final class CompletedMultipartObjectData extends Data
{
    /**
     * Create verified object metadata.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $checksum,
        public readonly int $size,
        public readonly string $mimeType,
        public readonly ?string $objectIdentity = null,
    ) {}
}
