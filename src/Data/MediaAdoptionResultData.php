<?php

declare(strict_types=1);

namespace Nvl\Media\Data;

use Spatie\LaravelData\Data;

/**
 * Reports reconciled Spatie media adoption counts and storage readiness.
 */
final class MediaAdoptionResultData extends Data
{
    /**
     * Create an immutable media adoption result.
     *
     * @param  list<string>  $missingPaths
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly string $mode,
        public readonly bool $ready,
        public readonly int $sourceMedia,
        public readonly int $sourceAssociations,
        public readonly int $sourceTranslations,
        public readonly int $sourceVariations,
        public readonly int $matchedMedia,
        public readonly int $matchedAssociations,
        public readonly int $matchedTranslations,
        public readonly int $matchedVariations,
        public readonly array $missingPaths,
        public readonly array $errors,
    ) {}
}
