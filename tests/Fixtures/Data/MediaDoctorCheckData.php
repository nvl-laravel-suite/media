<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data;

use Spatie\LaravelData\Data;

/**
 * One immutable Media doctor diagnostic.
 */
final class MediaDoctorCheckData extends Data
{
    /**
     * Create a doctor check.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly bool $passed,
        public readonly string $message,
    ) {}
}
