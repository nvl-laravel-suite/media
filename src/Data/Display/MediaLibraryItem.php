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

/** Privileged media-library projection for authorized management clients. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MediaLibraryItem extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $id,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $filename,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $title,

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
        public readonly string $disk,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $folder,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $collection,

        /** @var bool */
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isPublic,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $type,

        /** @var array<int, string> */
        #[LiteralTypeScriptType('string[]')]
        public readonly array $tags,

        /** @var int */
        #[LiteralTypeScriptType('number')]
        public readonly int $associationsCount,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $createdAt,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $updatedAt,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $previewUrl,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $url,
    ) {}
}
