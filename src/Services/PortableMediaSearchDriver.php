<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Models\Media;

/**
 * Portable substring and JSON search for moderate media datasets.
 */
final class PortableMediaSearchDriver implements MediaSearchDriver
{
    /**
     * @param  Builder<Media>  $query
     */
    public function apply(Builder $query, string $search): void
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $query->where(function (Builder $builder) use ($escaped, $search): void {
            $builder->where('filename', 'like', "%{$escaped}%")
                ->orWhere('type', 'like', "%{$escaped}%")
                ->orWhereJsonContains('tags', $search);
        });
    }
}
