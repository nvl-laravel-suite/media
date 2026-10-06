<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Data\MediaActorData;

/**
 * Returns the authorized projection for one registered Media owner slot.
 *
 * @api
 */
interface GetOwnerMediaSlotContract
{
    /**
     * Read one registered single-file slot without exposing persistence models.
     */
    public function execute(
        MediaActorData $actor,
        Model&HasNvlMedia $owner,
        string $slot,
    ): ?MediaLibraryItem;
}
