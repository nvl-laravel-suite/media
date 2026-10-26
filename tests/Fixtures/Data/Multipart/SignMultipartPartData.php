<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Multipart;

use Spatie\LaravelData\Data;

/**
 * Request for one signed multipart part.
 *
 * @api
 */
final class SignMultipartPartData extends Data
{
    /**
     * Create a part-signing request.
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly int $partNumber,
        public readonly string $checksum,
        public readonly int $byteLength,
    ) {}
}
