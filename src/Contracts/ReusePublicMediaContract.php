<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

interface ReusePublicMediaContract
{
    /**
     * Attach an existing public asset to another media-enabled model.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Media|string $media,
        Model&HasMedia $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): MediaAssociation;
}
