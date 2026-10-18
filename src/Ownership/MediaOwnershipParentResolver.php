<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Tenancy\LegacyMediaOwnerResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Reloads exact host records and ignores carried relation or owner attributes. @internal */
final readonly class MediaOwnershipParentResolver
{
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipBoundary $boundary, private OwnershipContext $context, private LegacyMediaOwnerResolver $legacy, private OwnerRegistry $identities) {}

    public function resolve(Model $owner, bool $lock = false): Model
    {
        if ($this->context->snapshot()->mode === OwnershipContextMode::Disabled) {
            return $this->legacy->resolve($owner, $lock);
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
            $key = $owner->getKey();
            if (! $owner->exists || (! is_int($key) && ! is_string($key))) {
                throw new OwnershipViolation('A persisted native Media owner is required.');
            }
            $query = $this->scopeAnchor($owner->newQuery())->whereKey($key);
            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->first() ?? throw new OwnershipViolation('The Media owner differs from the admitted native owner.');
        }

        return $owner;
    }

    /** Resolve only declared resource morphs or registered native anchors.
     * @return class-string<Model>
     */
    public function classForIdentity(string $type): string
    {
        foreach ($this->resources->all() as $definition) {
            if ((new $definition->model)->getMorphClass() === $type) {
                return $definition->model;
            }
        }

        return $this->identities->model($type);
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
