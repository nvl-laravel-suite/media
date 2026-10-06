<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;

/**
 * Generates one revision-checked image variation under a shared media mutation lock.
 *
 * @api
 */
interface GenerateImageVariationContract
{
    /**
     * Generate one variation when the media source revision is still current.
     *
     * @return MediaImageVariation|null Null for unsupported media or stale work
     */
    public function execute(
        Media $media,
        ConversionDefinition $definition,
        ?int $expectedRevision = null,
    ): ?MediaImageVariation;
}
