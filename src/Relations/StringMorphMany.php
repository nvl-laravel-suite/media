<?php

declare(strict_types=1);

namespace Nvl\Media\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;

/**
 * A morph-many relation whose foreign identifier is normalized to text.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
final class StringMorphMany extends MorphMany
{
    /**
     * Apply lazy-loading constraints using a normalized textual identifier.
     */
    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        $query = $this->getRelationQuery();

        $query
            ->where($this->morphType, $this->morphClass)
            ->where($this->foreignKey, '=', $this->normalizeIdentifier($this->getParentKey()))
            ->whereNotNull($this->foreignKey);
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = array_map(
            fn (mixed $key): ?string => $this->normalizeIdentifier($key),
            $this->getKeys($models, $this->localKey),
        );

        $this->whereInEager(
            'whereIn',
            $this->foreignKey,
            array_values(array_filter($keys, static fn (?string $key): bool => $key !== null)),
            $this->getRelationQuery(),
        );

        $this->getRelationQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * Build existence queries with a portable text comparison.
     *
     * @param  array<int, string>|string  $columns
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(
        Builder $query,
        Builder $parentQuery,
        $columns = ['*'],
    ): Builder {
        $parentKey = $this->textColumnExpression($query, $this->getQualifiedParentKeyName());
        $foreignKey = $query->getQuery()->getGrammar()->wrap($this->getExistenceCompareKey());

        return $query
            ->select($columns)
            ->whereRaw(new TextColumnComparison($parentKey, $foreignKey))
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }

    /**
     * Set textual morph attributes before persisting a related model.
     *
     * @param  TRelatedModel  $model
     */
    protected function setForeignAttributesForCreate(Model $model): void
    {
        $model->setAttribute(
            $this->getForeignKeyName(),
            $this->normalizeIdentifier($this->getParentKey()),
        );
        $model->setAttribute($this->getMorphType(), $this->morphClass);

        foreach ($this->getQuery()->pendingAttributes as $key => $value) {
            $attributes ??= $model->getAttributes();

            if (! array_key_exists($key, $attributes)) {
                $model->setAttribute($key, $value);
            }
        }

        $this->applyInverseRelationToModel($model);
    }

    /**
     * Normalize an Eloquent key for the string-compatible foreign column.
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
}
