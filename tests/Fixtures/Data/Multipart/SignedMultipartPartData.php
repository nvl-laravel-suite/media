<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Multipart;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Temporary credentials for uploading one object part.
 *
 * @api
 */
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

        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
