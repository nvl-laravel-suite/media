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

/** MediaDocument: type-specific payload for document media such as PDF files. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MediaDocument extends Data
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
