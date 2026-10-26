<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Multipart;

use Spatie\LaravelData\Data;

/**
 * Completion request for a multipart session.
 *
 * @api
 */
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
