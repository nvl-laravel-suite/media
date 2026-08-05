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

/** MediaImageBreakpoint: configured responsive image breakpoint exposed to clients. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MediaImageBreakpoint extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $label,

        /** @var int|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?int $width,

        /** @var int|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?int $height,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $format,

        /** @var int|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?int $quality,

        /** @var bool */
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $enabled,
    ) {}
}
