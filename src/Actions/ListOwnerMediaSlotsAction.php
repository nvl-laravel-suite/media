<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\ListOwnerMediaSlotsContract;
use Nvl\Media\Data\Display\OwnerMediaSlots;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Services\MediaOwnerBatchReader;

/**
 * Public read boundary for finite original-asset slot projections.
 *
 * @api
 */
final readonly class ListOwnerMediaSlotsAction implements ListOwnerMediaSlotsContract
{
    /** Retain the package-owned batch workflow. */
    public function __construct(private MediaOwnerBatchReader $reader) {}

    /** Return authorized slots after canonical admission.
     * @param  list<Model>  $owners
     * @param  list<string>  $slots
     */
    public function execute(array $owners, array $slots, MediaActorData $actor, ?string $locale = null): OwnerMediaSlots
    {
        return $this->reader->read($owners, $slots, $actor, $locale);
    }
}
