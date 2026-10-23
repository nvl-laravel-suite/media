<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\ListOwnerMediaContract;
use Nvl\Media\Data\Display\OwnerMediaPage;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Services\OwnerMediaReader;

/** Projects complete authorized owner associations without exposing storage handles.
 *
 * @api
 */
final readonly class ListOwnerMediaAction implements ListOwnerMediaContract
{
    /** Retain the bounded native owner reader. */
    public function __construct(private OwnerMediaReader $reader) {}

    /** Return one association page and complete totals for the selected owner and optional collection.
     * @param  list<string>  $variations
     */
    public function execute(MediaActorData $actor, Model&HasNvlMedia $owner, ?string $collection = null, int $page = 1, int $perPage = 25, array $variations = [], ?string $locale = null): OwnerMediaPage
    {
        return $this->reader->read($actor, $owner, $collection, $page, $perPage, $variations, $locale);
    }
}
