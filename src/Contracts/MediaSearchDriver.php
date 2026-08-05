<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Media\Models\Media;

/**
 * Applies consumer-replaceable search semantics to a Media query.
 */
interface MediaSearchDriver
{
    /**
     * @param  Builder<Media>  $query
     */
    public function apply(Builder $query, string $search): void;
}
