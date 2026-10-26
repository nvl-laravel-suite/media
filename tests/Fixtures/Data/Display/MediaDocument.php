<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Nvl\Media\Tests\Fixtures\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/** MediaDocument: type-specific payload for document media such as PDF files. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]

final class MediaDocument extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string|null */
        public readonly ?string $extension,

        /** @var string|null */
        public readonly ?string $mimeType,

        /** @var int */
        public readonly int $size,

        /** @var string */
        public readonly string $humanReadableSize,

        /** @var string */
        public readonly string $url,
    ) {}
}
