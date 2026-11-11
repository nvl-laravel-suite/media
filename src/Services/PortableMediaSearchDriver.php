<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Models\Media;

/**
 * Portable substring and JSON search for moderate media datasets.
 *
 * @internal
 */
final class PortableMediaSearchDriver implements MediaSearchDriver
{
    /**
     * @param  Builder<Media>  $query
     */
    public function apply(Builder $query, string $search): void
    {
        // An explicit escape character and lower() give the same literal, case-insensitive match on
        // SQLite, MySQL, MariaDB and PostgreSQL, whose LIKE defaults differ on both points.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
        $query->where(function (Builder $builder) use ($pattern, $search): void {
            $builder->whereRaw("lower(filename) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(type) like ? escape '!'", [$pattern])
                ->orWhereJsonContains('tags', $search);
        });
    }
}
