<?php

declare(strict_types=1);

namespace Nvl\Media\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Relations\StringMorphMany;
use Nvl\Media\Services\MediaBatchQueryBuilder;
use Nvl\Support\Enums\SystemActor;

/**
 * Adds only namespaced NVL entry points and lifecycle state to an Eloquent owner.
 *
 * @api
 *
 * @nvl-consumer-relation nvlMediaAssociations
 */
trait InteractsWithNvlMedia
{
    /** Return the one eager-loadable NVL relation.
     * @return MorphMany<MediaAssociation, $this>
     *
     * @internal
     */
    public function nvlMediaAssociations(): MorphMany
    {
        $related = new MediaAssociation;

        return (new StringMorphMany(
            $related->newQuery(),
            $this,
            $related->qualifyColumn('associable_type'),
            $related->qualifyColumn('associable_id'),
            $this->getKeyName(),
        ))->with('media')->orderBy('order');
    }

    /** Restrict host owners through explicit SQL View authorization without changing selections.
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHasNvlMedia(Builder $query, string $slot, Authenticatable|SystemActor $actor, MediaBatchAuthorization $policy): Builder
    {
        return app(MediaBatchQueryBuilder::class)->has($query, $slot, $actor, $policy);
    }

    /** Register NVL definitions when the owner overrides this hook. */
    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void {}
}
