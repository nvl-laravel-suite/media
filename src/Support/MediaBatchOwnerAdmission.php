<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Models\Media;
use Nvl\Media\Relations\ExactMediaText;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Owners\OwnerIdentity;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Reloads exact captured identities through canonical host and tenant scopes. */
final readonly class MediaBatchOwnerAdmission
{
    /** Retain the registered ownership runtime. */
    public function __construct(private Repository $configuration, private TenantBoundary $boundary, private TenantResourceRegistry $resources) {}

    /** Canonically reload each concrete owner class once. */
    public function admit(OwnerBatch $input): OwnerBatch
    {
        $groups = [];
        foreach ($input->identities() as $index => $identity) {
            $this->assertPrototype($input->owners()[$index]);
            $groups[$identity->model][] = $identity->id;
        }
        $loaded = [];
        foreach ($groups as $class => $ids) {
            $prototype = new $class;
            $query = $this->scope($prototype->newQuery()->withoutEagerLoads());
            $query->where(function (Builder $keys) use ($prototype, $ids): void {
                foreach ($ids as $id) {
                    $keys->orWhere(fn (Builder $key) => self::exact($key, $prototype->getQualifiedKeyName(), $id));
                }
            });
            foreach ($query->get() as $owner) {
                $identity = OwnerIdentity::fromModel($owner);
                $loaded[$identity->type][$identity->id] = $owner;
            }
        }
        $canonical = [];
        foreach ($input->identities() as $identity) {
            $canonical[] = $loaded[$identity->type][$identity->id]
                ?? throw new TenantBoundaryViolation('A Media batch owner is absent, deleted or outside the active host/tenant scope.');
        }

        return OwnerBatch::fromModels($canonical);
    }

    /** Reject capability, declared connection and table drift before package SQL. */
    public function assertPrototype(Model $owner): void
    {
        $prototype = new ($owner::class);
        $media = new Media;
        if (! $owner instanceof HasNvlMedia) {
            throw new InvalidArgumentException('Media batch owners must implement HasNvlMedia.');
        }
        if (($prototype->getConnectionName() !== null && $owner->getConnectionName() !== $prototype->getConnectionName())
            || ($owner->getConnectionName() !== null && $owner->getConnectionName() !== $prototype->getConnection()->getName())
            || $owner->getTable() !== $prototype->getTable()
            || $owner->getConnection() !== $media->getConnection()
            || $prototype->getConnection() !== $media->getConnection()) {
            throw new TenantBoundaryViolation('Media owners must retain their canonical table and package connection.');
        }
        if ($this->configuration->get('nvl-tenancy.enabled') === true) {
            $this->resources->forModel($prototype);
        }
    }

    /** Apply mandatory registered tenant ownership around retained host scopes.
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function scope(Builder $query): Builder
    {
        $this->groupCallerPredicates($query);
        $owner = $query->getModel();
        if (in_array(SoftDeletes::class, class_uses_recursive($owner), true) && method_exists($owner, 'getQualifiedDeletedAtColumn')) {
            $column = $owner->getQualifiedDeletedAtColumn();
            if (! is_string($column)) {
                throw new TenantBoundaryViolation('A canonical Media owner requires a valid soft-delete column.');
            }
            $query->whereNull($column);
        }
        if ($this->configuration->get('nvl-tenancy.enabled') === true) {
            return $this->boundary->query($query, $this->resources->forModel($owner)->key);
        }

        return $query;
    }

    /** Keep caller OR clauses inside each mandatory owner guard without changing other query state.
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    private function groupCallerPredicates(Builder $query): void
    {
        $base = $query->getQuery();
        if ($base->wheres === []) {
            return;
        }
        $nested = $base->forNestedWhere();
        $nested->wheres = $base->wheres;
        $nested->setBindings($base->getRawBindings()['where'], 'where');
        $base->wheres = [];
        $base->setBindings([], 'where');
        $base->addNestedWhereQuery($nested);
    }

    /** Compare exact textual identities with bound values.
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    public static function exact(Builder $query, string $column, string $value): void
    {
        $connection = $query->getModel()->getConnection();
        $wrapped = ExactMediaText::text($connection->getQueryGrammar()->wrap($column), $connection->getDriverName());
        $query->whereRaw(ExactMediaText::value($wrapped, $connection->getDriverName()), [$value, $value]);
    }
}
