<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/**
 * Reuse an existing public asset through the authorized attachment workflow.
 *
 * @api
 */
interface ReusePublicMediaContract
{
    /**
     * Attach an existing public asset to another media-enabled model.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Media|string $media,
        Model&HasNvlMedia $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): MediaAssociation;
}
