<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** PublicMediaImageSize: public-safe available image source for responsive rendering. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PublicMediaImageSize extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $label,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $name,

        /** @var string */
        #[LiteralTypeScriptType("'original' | 'variation'")]
        public readonly string $source,

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
        public readonly string $url,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $format,

        /** @var int|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?int $size,

        /** @var bool */
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isGenerated,
    ) {}
}
