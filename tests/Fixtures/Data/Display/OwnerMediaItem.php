<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Spatie\LaravelData\Data;
use stdClass;

/** Safe owner association display with requested public variation URLs.
 *
 * @api
 */
final class OwnerMediaItem extends Data
{
    /** Retain safe original facts, canonical collection and explicitly requested variation URLs.
     */
    public function __construct(
        public readonly OwnerMediaSlotItemData $media,
        public readonly string $collection,

        public readonly stdClass $variationUrls,
    ) {}
}
