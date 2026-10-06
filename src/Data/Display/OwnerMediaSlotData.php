<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use stdClass;

/** Requested slot-name object with explicit empty item lists.
 *
 * @api
 */
#[TypeScript]
final class OwnerMediaSlotData extends Data
{
    /** Retain numeric and ordinary names as an object map. */
    public function __construct(
        #[LiteralTypeScriptType('Record<string, Array<OwnerMediaSlotItemData>>')]
        public readonly stdClass $slots,
    ) {}
}
