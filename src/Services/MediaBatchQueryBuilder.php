<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Exceptions\MediaBatchReadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Relations\ExactMediaText;
use Nvl\Media\Support\MediaBatchOwnerAdmission;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/** SQL authorization and mandatory ownership applied before window bounds. */
final readonly class MediaBatchQueryBuilder
{
    /** Retain the canonical package ownership boundary. */
    public function __construct(private TenantBoundary $boundary, private MediaBatchOwnerAdmission $owners) {}

    /** Build the authorized active association set.
     * @param  array<string, array<array-key, array<array-key, int>>>  $ceilings
     * @return Builder<MediaAssociation>
     */
    public function batch(OwnerBatch $batch, array $ceilings, MediaActorData $actor, MediaBatchAuthorization $policy): Builder
    {
        $query = $this->associations()->where(function (Builder $pairs) use ($batch, $ceilings): void {
            foreach ($batch->identities() as $identity) {
                $pairs->orWhere(function (Builder $pair) use ($identity, $ceilings): void {
                    MediaBatchOwnerAdmission::exact($pair, 'associable_type', $identity->type);
                    MediaBatchOwnerAdmission::exact($pair, 'associable_id', $identity->id);
                    $pair->where(function (Builder $slots) use ($identity, $ceilings): void {
                        foreach (array_keys($ceilings[$identity->type][$identity->id]) as $slot) {
                            $slots->orWhere(fn (Builder $named) => MediaBatchOwnerAdmission::exact($named, 'collection', (string) $slot));
                        }
                    });
                });
            }
        });
        $query->where(fn (Builder $nested) => $policy->scopeAssociations($nested, $batch, $actor));
        $query->whereIn('media_id', $this->assets($batch, $actor, $policy)->select('id'));

        return $query;
    }

    /** Rank only authorized rows and transfer at most each canonical declaration.
     * @param  array<string, array<array-key, array<array-key, int>>>  $ceilings
     * @param  Builder<MediaAssociation>  $query
     */
    public function bounded(Builder $query, OwnerBatch $batch, array $ceilings): QueryBuilder
    {
        $visible = $query->toBase();
        $grammar = $visible->getGrammar();
        $driver = $query->getModel()->getConnection()->getDriverName();
        $window = match ($driver) {
            'mysql', 'mariadb' => 'ROW_NUMBER() OVER (PARTITION BY BINARY associable_type, BINARY associable_id, BINARY collection ORDER BY `order` ASC, id ASC) AS nvl_media_rank',
            'pgsql' => 'ROW_NUMBER() OVER (PARTITION BY associable_type COLLATE "C", associable_id COLLATE "C", collection COLLATE "C" ORDER BY "order" ASC, id ASC) AS nvl_media_rank',
            default => 'ROW_NUMBER() OVER (PARTITION BY associable_type COLLATE BINARY, associable_id COLLATE BINARY, collection COLLATE BINARY ORDER BY "order" ASC, id ASC) AS nvl_media_rank',
        };
        $ranked = (clone $visible)->select(['id', 'media_id', 'associable_type', 'associable_id', 'collection'])->selectRaw($window);

        return $visible->newQuery()->fromSub($ranked, 'nvl_ranked_media')->where(function (QueryBuilder $pairs) use ($batch, $ceilings, $driver, $grammar): void {
            foreach ($batch->identities() as $identity) {
                $pairs->orWhere(function (QueryBuilder $pair) use ($identity, $ceilings, $driver, $grammar): void {
                    foreach (['associable_type' => $identity->type, 'associable_id' => $identity->id] as $column => $value) {
                        $pair->whereRaw(ExactMediaText::value(ExactMediaText::text($grammar->wrap($column), $driver), $driver), [$value, $value]);
                    }
                    $pair->where(function (QueryBuilder $slots) use ($identity, $ceilings, $driver, $grammar): void {
                        foreach ($ceilings[$identity->type][$identity->id] as $slot => $ceiling) {
                            $slots->orWhere(function (QueryBuilder $named) use ($slot, $ceiling, $driver, $grammar): void {
                                $name = (string) $slot;
                                $named->whereRaw(ExactMediaText::value(ExactMediaText::text($grammar->wrap('collection'), $driver), $driver), [$name, $name]);
                                $named->where('nvl_media_rank', '<=', $ceiling);
                            });
                        }
                    });
                });
            }
        })->orderBy('nvl_media_rank')->orderBy('id');
    }

    /** Restrict host owners through a complete SQL View policy and mandatory asset availability.
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function has(Builder $query, string $slot, MediaActorData $actor, MediaBatchAuthorization $policy): Builder
    {
        if (trim($slot) === '' || mb_strlen($slot) > 50) {
            throw new MediaBatchReadException('The Media host slot must be nonempty and at most fifty characters.');
        }
        $owner = $query->getModel();
        $this->owners->assertPrototype($owner);
        if ($query->getConnection() !== $owner->getConnection()
            || $query->getQuery()->from !== $owner->getTable()
            || $query->getQuery()->unions !== null) {
            throw new TenantBoundaryViolation('The Media host query must retain its canonical owner table and connection.');
        }
        $query = $this->owners->scope($query);
        $associations = $this->associations();
        MediaBatchOwnerAdmission::exact($associations, 'associable_type', $owner->getMorphClass());
        MediaBatchOwnerAdmission::exact($associations, 'collection', $slot);
        $connection = $associations->getModel()->getConnection();
        $grammar = $connection->getQueryGrammar();
        $left = ExactMediaText::text($grammar->wrap($associations->getModel()->qualifyColumn('associable_id')), $connection->getDriverName());
        $right = ExactMediaText::text($grammar->wrap($owner->getQualifiedKeyName()), $connection->getDriverName());
        $associations->whereRaw(new ExactMediaText($left, $right, $connection->getDriverName()));
        $associations->whereIn('media_id', $this->available(Media::query()->withoutEagerLoads())->select('id'));
        $associations->where(fn (Builder $nested) => $policy->scopeHostAssociations($nested, $owner, $actor, $slot));

        return $query->whereExists($associations->selectRaw('1')->toBase());
    }

    /** Build a scoped active association query.
     * @return Builder<MediaAssociation>
     */
    public function associations(): Builder
    {
        $query = MediaAssociation::query()->withoutEagerLoads();
        if (! in_array($query->getModel()->getConnection()->getDriverName(), ['sqlite', 'pgsql', 'mysql', 'mariadb'], true)) {
            throw new MediaBatchReadException('Media batch reads require SQLite, PostgreSQL, MySQL or MariaDB window support.');
        }

        return $this->boundary->query($query, 'media.associations')->where($query->getModel()->qualifyColumn('is_active'), true);
    }

    /** Build authorized available assets before association ranking or asset hydration.
     * @return Builder<Media>
     */
    public function assets(OwnerBatch $batch, MediaActorData $actor, MediaBatchAuthorization $policy): Builder
    {
        $query = $this->available(Media::query()->withoutEagerLoads());
        $nested = (new Media)->newModelQuery();
        $policy->scopeMedia($nested, $batch, $actor);
        $query->getQuery()->addNestedWhereQuery($nested->getQuery());

        return $query;
    }

    /** Preserve available lifecycle, canonical ownership and soft deletion.
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function available(Builder $query): Builder
    {
        return $this->boundary->query($query, 'media.assets')->available()->whereNull($query->getModel()->qualifyColumn('deleted_at'));
    }
}
