<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Mutations;

use Spatie\LaravelData\Data;

/**
 * Immutable request for one granted platform Media import.
 *
 * @api
 */
final class ImportPlatformMediaData extends Data
{
    public function __construct(
        public string $grantId,
        public int $expectedGrantRevision,
        public int $expectedSourceRevision,
        public string $idempotencyKey,
    ) {}
}
