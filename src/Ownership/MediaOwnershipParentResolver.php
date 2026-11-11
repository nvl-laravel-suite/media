<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use ReflectionMethod;

/**
 * Reloads exact host records and ignores carried relation or owner attributes.
 *
 * @internal
 */
final readonly class MediaOwnershipParentResolver
{
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipBoundary $boundary, private OwnershipContext $context, private OwnerRegistry $identities) {}

    public function resolve(Model $owner, bool $lock = false): Model
    {
        $class = $owner::class;
        $prototype = new $class;
        $key = $owner->getKey();
        if (! $owner->exists || (! is_int($key) && ! is_string($key)) || $key === ''
            || $owner->getTable() !== $prototype->getTable()
            || $owner->getKeyName() !== $prototype->getKeyName()
            || ($owner->getConnectionName() !== null && $owner->getConnectionName() !== $prototype->getConnection()->getName())
            || $owner->getConnection() !== $prototype->getConnection()
            || $owner->getRawOriginal($owner->getKeyName()) !== $key) {
            throw new OwnershipViolation('A Media host must retain its persisted canonical identity.');
        }
        foreach ($this->resources->all() as $definition) {
            if ($definition->model !== $owner::class) {
                continue;
            }
            if ($definition->ownership->strategy === 'unscoped' && MediaOwnership::active()) {
                break;
            }
            $this->boundary->assertRecord($owner, $definition->key);
            $query = $this->boundary->query((new $definition->model)->newQuery()->useWritePdo(), $definition->key)->whereKey($owner->getKey());
            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->first() ?? throw new OwnershipViolation('The Media host record is unavailable.');
        }
        if (MediaOwnership::active()) {
            $query = $this->scopeAnchor($prototype->newQuery())->whereKey($key);
            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->first() ?? throw new OwnershipViolation('The Media owner differs from the admitted native owner.');
        }

        $query = $prototype->newQuery()->useWritePdo()->whereKey($key);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new OwnershipViolation('The Media host record is unavailable.');
    }

    /** Resolve only declared resource morphs or registered native anchors.
     * @return class-string<Model>
     */
    public function classForIdentity(string $type): string
    {
        $aliased = Relation::getMorphedModel($type);

        foreach ($this->resources->all() as $definition) {
            $model = $definition->model;

            // Eloquent's own getMorphClass() only answers with the model's morph-map alias or class name.
            if ($model !== $type && $model !== $aliased && ! $this->morphClassNeedsInstance($model)) {
                continue;
            }

            if ((new $model)->getMorphClass() === $type) {
                return $model;
            }
        }

        return $this->identities->model($type);
    }

    /**
     * Whether only an instance can answer: a customised morph class, or the violation an enforced morph map raises.
     *
     * @param  class-string<Model>  $model
     */
    private function morphClassNeedsInstance(string $model): bool
    {
        return (new ReflectionMethod($model, 'getMorphClass'))->class !== Model::class
            || (Relation::requiresMorphMap() && ! in_array($model, Relation::morphMap(), true));
    }

    /** Reload a scalar association target under its canonical resource or native anchor. */
    public function resolveIdentity(string $type, int|string $id): Model
    {
        $class = $this->classForIdentity($type);
        $prototype = new $class;
        $query = $prototype->newQuery()->useWritePdo()->whereKey($id);
        $resource = null;
        foreach ($this->resources->all() as $definition) {
            if ($definition->model === $class && $definition->ownership->strategy !== 'unscoped') {
                $resource = $definition->key;
                break;
            }
        }
        $query = $resource === null ? $this->scopeAnchor($query) : $this->boundary->query($query, $resource);
        $target = $query->lockForUpdate()->first() ?? throw new OwnershipViolation('The Media association target is unavailable in its canonical owner.');
        $key = $target->getKey();
        if ((! is_int($key) && ! is_string($key)) || (string) $key !== (string) $id || $target->getMorphClass() !== $type) {
            throw new OwnershipViolation('Media association identities must preserve their exact native key and morph type.');
        }

        return $target;
    }

    /** Bound an owner-anchor query through the root's declared native class and full admitted reference.
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function scopeAnchor(Builder $query): Builder
    {
        $definition = $this->resources->get('media.assets')->ownership;
        $snapshot = $this->context->snapshot();
        $class = $definition->owner === null ? null : $this->identities->model($definition->owner);
        $model = $query->getModel();
        $prototype = $class === null ? null : new $class;
        $base = $query->getQuery();
        if ($snapshot->mode !== OwnershipContextMode::Owner || $snapshot->owner === null
            || $prototype === null || $model::class !== $class || $model->getTable() !== $prototype->getTable()
            || $model->getConnection() !== $prototype->getConnection() || $model->getConnection() !== MediaModelRegistry::newInstance()->getConnection()
            || $prototype->getMorphClass() !== $snapshot->owner->type || $base->from !== $prototype->getTable()
            || $base->getConnection() !== $prototype->getConnection() || $base->unions !== null) {
            throw new OwnershipViolation('A Media anchor must retain the exact admitted native owner and physical source.');
        }
        $this->boundary->attributes('media.assets');

        return $query->useWritePdo()->whereKey($snapshot->owner->key);
    }
}
