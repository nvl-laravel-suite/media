<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Nvl\Support\Owners\OwnerResultMap;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use stdClass;

/** Exact native owner maps and stable identity order.
 *
 * @api
 */
#[TypeScript]
final class OwnerMediaSlots extends Data
{
    /** Retain object-valued maps, including numeric identities.
     * @param  list<array{type: string, id: string}>  $order
     */
    public function __construct(
        #[LiteralTypeScriptType('Record<string, Record<string, OwnerMediaSlotData>>')]
        public readonly stdClass $owners,
        #[LiteralTypeScriptType('Array<{ type: string; id: string }>')]
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
