<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Data\Display\OwnerMediaPage;
use Nvl\Media\Data\MediaActorData;

/** Reads complete authorized owner collections through bounded pages and aggregates.
 *
 * @api
 */
interface ListOwnerMediaContract
{
    /** Return one association page and complete totals for the selected owner and optional collection.
     * @param  list<string>  $variations
     */
    public function execute(MediaActorData $actor, Model&HasNvlMedia $owner, ?string $collection = null, int $page = 1, int $perPage = 25, array $variations = [], ?string $locale = null): OwnerMediaPage;
}
