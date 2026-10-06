<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Data\MediaActorData;

/**
 * Clears one registered Media owner slot through the package lifecycle workflow.
 *
 * @api
 */
interface ClearOwnerMediaSlotContract
{
    /**
     * Remove the current slot association when one exists.
     */
    public function execute(
        MediaActorData $actor,
        Model&HasNvlMedia $owner,
        string $slot,
        ?string $idempotencyKey = null,
    ): void;
}
