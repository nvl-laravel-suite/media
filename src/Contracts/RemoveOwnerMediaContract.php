<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Data\MediaActorData;

/** Removes explicitly selected owner usages and cleans an authorized orphan atomically.
 *
 * @api
 */
interface RemoveOwnerMediaContract
{
    /** Remove the selected asset from this owner and optional exact collection. */
    public function execute(MediaActorData $actor, Model&HasNvlMedia $owner, string $mediaId, ?string $collection = null): void;
}
