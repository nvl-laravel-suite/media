<?php

declare(strict_types=1);

namespace Nvl\Media\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Media\MediaOwner;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Relations\StringMorphMany;
use Nvl\Media\Services\MediaOwnerFactory;

/** Adds only namespaced NVL entry points and lifecycle state to an Eloquent owner. */
trait InteractsWithNvlMedia
{
    private ?MediaOwner $nvlMediaOwner = null;

    /** Boot NVL deletion independently of any other media implementation. */
    public static function bootInteractsWithNvlMedia(): void
    {
        static::deleted(static function (self $owner): void {
            $owner->nvlMedia()->ownerDeleted();
        });
    }

    /** Resolve the owner-scoped API from Laravel's container at the model boundary. */
    public function nvlMedia(): MediaOwner
    {
        if ($this->nvlMediaOwner === null || ! $this->nvlMediaOwner->owns($this)) {
            $this->nvlMediaOwner = app(MediaOwnerFactory::class)->for($this);
        }

        return $this->nvlMediaOwner;
    }

    /** Return the one eager-loadable NVL relation.
     * @return MorphMany<MediaAssociation, $this>
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

    /** Register NVL definitions when the owner overrides this hook. */
    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void {}
}
