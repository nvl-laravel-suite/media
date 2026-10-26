<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Nvl\Support\Owners\OwnerResultMap;
use Spatie\LaravelData\Data;
use stdClass;

/** Exact native owner maps and stable identity order.
 *
 * @api
 */
final class OwnerMediaSlots extends Data
{
    /** Retain object-valued maps, including numeric identities.
     * @param  list<array{type: string, id: string}>  $order
     */
    public function __construct(

        public readonly stdClass $owners,

        public readonly array $order,
    ) {}

    /** Serialize complete admitted results.
     * @param  OwnerResultMap<OwnerMediaSlotData>  $map
     */
    public static function fromMap(OwnerResultMap $map): self
    {
        return new self($map->jsonSerialize(), $map->order());
    }
}
