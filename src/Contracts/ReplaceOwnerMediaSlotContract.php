<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Data\MediaActorData;

/**
 * Replaces one registered Media owner slot through the package lifecycle workflow.
 *
 * @api
 */
interface ReplaceOwnerMediaSlotContract
{
    /**
     * Replace the current slot association with an authorized existing Media record.
     */
    public function execute(
        MediaActorData $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        string $mediaId,
        ?string $idempotencyKey = null,
    ): MediaLibraryItem;
}
