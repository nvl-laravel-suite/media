<?php

declare(strict_types=1);

namespace Nvl\Media\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One immutable Media doctor diagnostic.
 */
#[TypeScript]
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
