<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Multipart;

use Spatie\LaravelData\Data;

/**
 * Server-verified metadata returned by object storage after completion.
 *
 * @api
 */
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
