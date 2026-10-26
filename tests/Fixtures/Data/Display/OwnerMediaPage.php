<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Spatie\LaravelData\Data;
use stdClass;

/** Bounded owner association page and complete authorized original-asset aggregates.
 *
 * @api
 */
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

        public readonly stdClass $collectionCounts,
        public readonly int $distinctAssetCount,
        public readonly int $originalBytes,
    ) {}
}
