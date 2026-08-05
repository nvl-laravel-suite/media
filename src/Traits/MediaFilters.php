<?php

declare(strict_types=1);

namespace Nvl\Media\Traits;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Definitions\FilterDefinition;
use Nvl\Filterable\Definitions\FilterSchema;
use Nvl\Filterable\Definitions\SortDefinition;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Enums\FilterValueType;
use Nvl\Filterable\Exceptions\FilterableException;
use Nvl\Filterable\Traits\Filterable;
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;

/**
 * Defines the allowlisted filter and sort surface for Media queries.
 */
trait MediaFilters
{
    /** @use Filterable<Media> */
    use Filterable;

    /**
     * Declare the public Media query contract.
     */
    public function filterSchema(): FilterSchema
    {
        return new FilterSchema(
            filters: [
                new FilterDefinition(
                    'search',
                    'filename',
                    operators: [FilterOperator::Equals],
                    handler: static function (Builder $query, FilterCriterion $criterion): Builder {
                        if (! is_string($criterion->value)) {
                            throw new FilterableException('The media search filter must be a string.');
                        }

                        $term = $criterion->value;

                        return $query->where(function (Builder $searchQuery) use ($term): void {
                            $searchQuery->where('filename', 'like', "%{$term}%")
                                ->orWhere('type', 'like', "%{$term}%")
                                ->orWhereJsonContains('tags', $term);
                        });
                    },
                ),
                new FilterDefinition(
                    'type',
                    'type',
                    FilterValueType::Enum,
                    [FilterOperator::Equals, FilterOperator::In],
                    enumValues: array_column(MediaType::cases(), 'value'),
                ),
                new FilterDefinition('disk', 'disk'),
                new FilterDefinition('is_public', 'is_public', FilterValueType::Boolean),
            ],
            sorts: array_map(
                static fn (string $column): SortDefinition => new SortDefinition($column, $column),
                [...MediaFilter::ALLOWED_SORT_COLUMNS, 'id'],
            ),
            defaultSorts: ['-created_at'],
            tieBreakerSort: 'id',
        );
    }

    /**
     * Filter by filename, type, or tag.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function filterSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $searchQuery) use ($term): void {
            $searchQuery->where('filename', 'like', "%{$term}%")
                ->orWhere('type', 'like', "%{$term}%")
                ->orWhereJsonContains('tags', $term);
        });
    }
}
