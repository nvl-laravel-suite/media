<?php

declare(strict_types=1);

namespace Nvl\Media\Tenancy;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;

/** Keeps only the historical grant API on its single legacy authority. @internal
 * @implements Scope<Model>
 */
final readonly class MediaLegacyGrantScope implements Scope
{
    /** @param Builder<covariant Model> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $query = $model->newModelQuery();
        $query->setQuery($builder->getQuery());
        Container::getInstance()->make(TenantBoundary::class)->query($query, 'media.catalog-grants');
    }
}
