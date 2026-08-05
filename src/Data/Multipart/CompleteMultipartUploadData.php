<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Multipart;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Completion request for a multipart session.
 */
#[TypeScript]
final class CompleteMultipartUploadData extends Data
{
    /**
     * Create a completion request.
     *
     * @param  list<CompletedMultipartPartData>  $parts
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly array $parts,
    ) {}
}
