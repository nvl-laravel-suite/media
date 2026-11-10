<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Tests\Fixtures\SerializesMediaProjection;

/** MediaImageVariation: read-only DTO representing a generated image variation. */
final class MediaImageVariationPayload implements Arrayable, JsonSerializable
{
    use SerializesMediaProjection;

    public function __construct(
        /**
         * @var string
         */
        public readonly string $id,

        /**
         * @var string
         */
        public readonly string $label,

        /**
         * @var int
         */
        public readonly int $width,

        /**
         * @var int
         */
        public readonly int $height,

        /**
         * @var int
         */
        public readonly int $size,

        /**
         * @var string
         */
        public readonly string $format,

        /**
         * @var int
         */
        public readonly int $quality,

        /**
         * @var string|null
         */
        public readonly ?string $url = null,
    ) {}

    /**
     * Create DTO from Eloquent model.
     */
    public static function fromModel(MediaImageVariation $variation): self
    {
        $media = $variation->media;
        $url = $media instanceof Media
            ? $media->buildUrl(['v' => $variation->label])
            : null;

        return new self(
            id: $variation->id,
            label: $variation->label,
            width: $variation->width,
            height: $variation->height,
            size: $variation->size,
            format: $variation->format,
            quality: $variation->quality,
            url: $url,
        );
    }
}
