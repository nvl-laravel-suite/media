<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** PublicMediaImage: public-safe image payload for storefront rendering. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PublicMediaImage extends Data
{
    use DataTransform;

    public function __construct(
        /** @var int|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?int $width,

        /** @var int|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?int $height,

        /** @var float|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?float $aspectRatio,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $src,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $previewUrl,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $srcSet,

        /** @var array<int, PublicMediaImageSize> */
        #[DataCollectionOf(PublicMediaImageSize::class)]
        public readonly array $sizes,
    ) {}
}
