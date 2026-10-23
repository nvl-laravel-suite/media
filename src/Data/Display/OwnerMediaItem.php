<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use stdClass;

/** Safe owner association display with requested public variation URLs.
 *
 * @api
 */
#[TypeScript]
final class OwnerMediaItem extends Data
{
    /** Retain safe original facts, canonical collection and explicitly requested variation URLs.
     */
    public function __construct(
        public readonly OwnerMediaSlotItemData $media,
        public readonly string $collection,
        #[LiteralTypeScriptType('Record<string, string | null>')]
        public readonly stdClass $variationUrls,
    ) {}
}
