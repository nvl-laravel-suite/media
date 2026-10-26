<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Spatie\LaravelData\Data;
use stdClass;

/** Requested slot-name object with explicit empty item lists.
 *
 * @api
 */
final class OwnerMediaSlotData extends Data
{
    /** Retain numeric and ordinary names as an object map. */
    public function __construct(

        public readonly stdClass $slots,
    ) {}
}
