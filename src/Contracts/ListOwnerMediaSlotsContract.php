<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Data\Display\OwnerMediaSlots;
use Nvl\Media\Data\MediaActorData;

/** Public bounded, authorized owner-slot projection.
 *
 * @api
 */
interface ListOwnerMediaSlotsContract
{
    /** Return original-asset slot DTOs for canonically admitted owners.
     * @param  list<Model>  $owners
     * @param  list<string>  $slots
     */
    public function execute(array $owners, array $slots, MediaActorData $actor, ?string $locale = null): OwnerMediaSlots;
}
