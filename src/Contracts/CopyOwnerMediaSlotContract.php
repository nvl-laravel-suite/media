<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Data\MediaActorData;

/**
 * Copies one authorized Media source into a registered owner slot.
 *
 * @api
 */
interface CopyOwnerMediaSlotContract
{
    /**
     * Copy verified source bytes through canonical destination ingestion.
     */
    public function execute(
        MediaActorData $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        string $sourceMediaId,
        ?string $idempotencyKey = null,
    ): MediaLibraryItem;
}
