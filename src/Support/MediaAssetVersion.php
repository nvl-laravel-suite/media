<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;

/**
 * Builds consistent cache identities for original media and generated variations.
 */
final class MediaAssetVersion
{
    /**
     * Build the complete entity tag value for an asset.
     */
    public static function etag(Media $media, ?MediaImageVariation $variation = null): string
    {
        if (! $variation instanceof MediaImageVariation) {
            return $media->hash;
        }

        return hash('sha256', implode('|', [
            $media->hash,
            $variation->id,
            $variation->label,
            $variation->format,
            $variation->size,
            $variation->updated_at?->format('U.u') ?? '0',
        ]));
    }

    /**
     * Build a compact version suitable for public URL cache busting.
     */
    public static function short(Media $media, ?MediaImageVariation $variation = null): string
    {
        return self::shortFromEtag(self::etag($media, $variation));
    }

    /**
     * Build a compact cache version from an authoritative entity tag.
     */
    public static function shortFromEtag(string $etag): string
    {
        return substr(hash('sha256', $etag), 0, 16);
    }
}
