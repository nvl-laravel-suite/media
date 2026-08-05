<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** MediaImageVariation: read-only DTO representing a generated image variation. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MediaImageVariationPayload extends Data
{
    use DataTransform;

    public function __construct(
        /**
         * @var string
         */
        #[LiteralTypeScriptType('string')]
        public readonly string $id,

        /**
         * @var string
         */
        #[LiteralTypeScriptType('string')]
        public readonly string $label,

        /**
         * @var int
         */
        #[LiteralTypeScriptType('number')]
        public readonly int $width,

        /**
         * @var int
         */
        #[LiteralTypeScriptType('number')]
        public readonly int $height,

        /**
         * @var int
         */
        #[LiteralTypeScriptType('number')]
        public readonly int $size,

        /**
         * @var string
         */
        #[LiteralTypeScriptType('string')]
        public readonly string $format,

        /**
         * @var int
         */
        #[LiteralTypeScriptType('number')]
        public readonly int $quality,

        /**
         * @var string|null
         */
        #[LiteralTypeScriptType('string | null')]
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
