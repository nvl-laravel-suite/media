<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Multipart;

use Spatie\LaravelData\Data;

/**
 * Provider receipt for one uploaded part.
 *
 * @api
 */
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
