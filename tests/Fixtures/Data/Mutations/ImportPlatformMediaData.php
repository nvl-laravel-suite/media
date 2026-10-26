<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Mutations;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
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
        #[MapInputName('idempotency_key')] #[MapOutputName('idempotency_key')] public string $idempotencyKey,
    ) {}
}
