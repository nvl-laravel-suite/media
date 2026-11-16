<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Models\Media;
use Nvl\Support\Contracts\TextMatcher;

/**
 * Portable substring and JSON search for moderate media datasets.
 *
 * @internal
 */
final readonly class PortableMediaSearchDriver implements MediaSearchDriver
{
    public function __construct(private TextMatcher $textMatcher) {}

    /**
     * @param  Builder<Media>  $query
     */
    public function apply(Builder $query, string $search): void
    {
        $query->where(function (Builder $builder) use ($search): void {
            $this->textMatcher->contains($builder->getQuery(), $builder->qualifyColumn('filename'), $search);
            $this->textMatcher->contains($builder->getQuery(), $builder->qualifyColumn('type'), $search, boolean: 'or');
            $builder->orWhereJsonContains($builder->qualifyColumn('tags'), $search);
        });
    }
}
