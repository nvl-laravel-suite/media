<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Ingestion;

use Spatie\LaravelData\Data;

/**
 * Describes a verified replacement object staged before its database swap.
 */
final class StagedMediaFileData extends Data
{
    /**
     * Create staged replacement metadata.
     */
    public function __construct(
        public readonly ValidatedMediaFileData $validatedFile,
        public readonly string $hash,
        public readonly string $path,
    ) {}
}
