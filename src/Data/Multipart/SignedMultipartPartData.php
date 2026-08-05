<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Multipart;

use DateTimeImmutable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Temporary credentials for uploading one object part.
 */
#[TypeScript]
final class SignedMultipartPartData extends Data
{
    /**
     * Create signed part credentials.
     *
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $partNumber,
        public readonly string $url,
        public readonly array $headers,
        #[LiteralTypeScriptType('string')]
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
