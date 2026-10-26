<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/**
 * Opts an Eloquent owner into NVL media without occupying another library's model API.
 *
 * @api
 */
interface HasNvlMedia
{
    /**
     * Return NVL associations for eager loading.
     *
     * @return MorphMany<MediaAssociation, covariant Model>
     *
     * @internal
     */
    public function nvlMediaAssociations(): MorphMany;

    /** Register NVL slots and conversions independently of third-party hooks. */
    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void;
}
