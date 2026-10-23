<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use stdClass;

/** Bounded owner association page and complete authorized original-asset aggregates.
 *
 * @api
 */
#[TypeScript]
final class OwnerMediaPage extends Data
{
    /** Retain the page and totals over the same authorized owner/collection query.
     * @param  list<OwnerMediaItem>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        #[LiteralTypeScriptType('Record<string, number>')]
        public readonly stdClass $collectionCounts,
        public readonly int $distinctAssetCount,
        public readonly int $originalBytes,
    ) {}
}
