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

/** MediaFile: generic type-specific payload for non-specialized media files. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MediaFile extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $extension,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $mimeType,

        /** @var int */
        #[LiteralTypeScriptType('number')]
        public readonly int $size,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $humanReadableSize,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $url,
    ) {}
}
