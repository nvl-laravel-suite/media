<?php

declare(strict_types=1);

namespace Nvl\Media\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use LogicException;

/**
 * A polymorphic many-to-many relation whose polymorphic identifier is stored
 * as text so integer, UUID, and ULID owner keys can share one pivot table.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
final class StringMorphToMany extends MorphToMany
{
    /**
     * Constrain lazy relation queries with a normalized textual owner key.
     */
    protected function addWhereConstraints(): static
    {
        $this->query
            ->where(
                $this->getQualifiedForeignPivotKeyName(),
                '=',
                $this->normalizeIdentifier($this->parent->getAttribute($this->parentKey)),
            )
            ->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);

        return $this;
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = array_map(
            fn (mixed $key): ?string => $this->normalizeIdentifier($key),
            $this->getKeys($models, $this->parentKey),
        );

        $this->whereInEager(
            'whereIn',
            $this->getQualifiedForeignPivotKeyName(),
            array_values(array_filter($keys, static fn (?string $key): bool => $key !== null)),
        );

        $this->query->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);
    }

    /**
     * Build existence queries without comparing an integer owner column to the
     * textual polymorphic pivot identifier on strict databases.
     *
     * @param  array<int, string>|string  $columns
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(
        Builder $query,
        Builder $parentQuery,
        $columns = ['*'],
    ): Builder {
        $this->performJoin($query);

        $parentKey = $this->textColumnExpression($query, $this->getQualifiedParentKeyName());
        $pivotKey = $query->getQuery()->getGrammar()->wrap($this->getExistenceCompareKey());

        return $query
            ->select($columns)
            ->whereRaw(new TextColumnComparison($parentKey, $pivotKey))
            ->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);
    }

    /**
     * Constrain direct pivot mutations with the same textual owner key.
     */
    public function newPivotQuery(): QueryBuilder
    {
        $query = $this->newPivotStatement();

        foreach ($this->pivotWheres as $arguments) {
            $this->applyPivotWhere($query, $arguments);
        }

        foreach ($this->pivotWhereIns as $arguments) {
            $this->applyPivotWhereIn($query, $arguments);
        }

        foreach ($this->pivotWhereNulls as $arguments) {
            $this->applyPivotWhereNull($query, $arguments);
        }

        return $query
            ->where(
                $this->getQualifiedForeignPivotKeyName(),
                $this->normalizeIdentifier($this->parent->getAttribute($this->parentKey)),
            )
            ->where($this->morphType, $this->morphClass);
    }

    /**
     * Normalize an Eloquent key for the string-compatible pivot column.
     */
    private function normalizeIdentifier(mixed $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new InvalidArgumentException('Polymorphic identifiers must be integers or strings.');
        }

        return (string) $identifier;
    }

    /**
     * Build a driver-portable textual column expression.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function textColumnExpression(Builder $query, string $column): string
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        return match ($query->getModel()->getConnection()->getDriverName()) {
            'pgsql', 'sqlite' => "CAST({$wrapped} AS TEXT)",
            'sqlsrv' => "CAST({$wrapped} AS NVARCHAR(255))",
            default => "CAST({$wrapped} AS CHAR)",
        };
    }

    /**
     * @param  mixed  $arguments  Framework-owned pivot where arguments
     */
    private function applyPivotWhere(QueryBuilder $query, mixed $arguments): void
    {
        if (! is_array($arguments)) {
            throw new LogicException('Pivot where constraints must be arrays.');
        }

        $column = $arguments[0] ?? null;
        if (! is_string($column) && ! $column instanceof Expression) {
            throw new LogicException('Pivot where columns must be strings or query expressions.');
        }

        if (count($arguments) === 2) {
            $query->where($column, $arguments[1]);

            return;
        }

        $boolean = $arguments[3] ?? 'and';
        if (! is_string($boolean)) {
            throw new LogicException('Pivot where booleans must be strings.');
        }

        $query->where($column, $arguments[1] ?? null, $arguments[2] ?? null, $boolean);
    }

    /**
     * @param  mixed  $arguments  Framework-owned pivot where-in arguments
     */
    private function applyPivotWhereIn(QueryBuilder $query, mixed $arguments): void
    {
        if (! is_array($arguments)) {
            throw new LogicException('Pivot where-in constraints must be arrays.');
        }

        $column = $arguments[0] ?? null;
        $values = $arguments[1] ?? null;
        $boolean = $arguments[2] ?? 'and';
        $not = $arguments[3] ?? false;

        if ((! is_string($column) && ! $column instanceof Expression)
            || ! is_iterable($values)
            || ! is_string($boolean)
            || ! is_bool($not)) {
            throw new LogicException('Pivot where-in constraints are malformed.');
        }

        $query->whereIn($column, $values, $boolean, $not);
    }

    /**
     * @param  mixed  $arguments  Framework-owned pivot null arguments
     */
    private function applyPivotWhereNull(QueryBuilder $query, mixed $arguments): void
    {
        if (! is_array($arguments)) {
            throw new LogicException('Pivot null constraints must be arrays.');
        }

        $column = $arguments[0] ?? null;
        $boolean = $arguments[1] ?? 'and';
        $not = $arguments[2] ?? false;

        if ((! is_string($column) && ! $column instanceof Expression)
            || ! is_string($boolean)
            || ! is_bool($not)) {
            throw new LogicException('Pivot null constraints are malformed.');
        }

        $query->whereNull($column, $boolean, $not);
    }
}
