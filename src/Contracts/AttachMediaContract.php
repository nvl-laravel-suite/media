<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

interface AttachMediaContract
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Media $media,
        Model $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): MediaAssociation;
}
