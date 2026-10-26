<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Nvl\Media\Tests\Fixtures\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/** MediaImageSize: concrete or expected responsive image size for an image media item. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]

final class MediaImageSize extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string */
        public readonly string $label,

        /** @var string */
        public readonly string $name,

        /** @var string */
        public readonly string $source,

        /** @var int|null */
        public readonly ?int $width,

        /** @var int|null */
        public readonly ?int $height,

        /** @var float|null */
        public readonly ?float $aspectRatio,

        /** @var string|null */
        public readonly ?string $url,

        /** @var string|null */
        public readonly ?string $format,

        /** @var int|null */
        public readonly ?int $quality,

        /** @var int|null */
        public readonly ?int $size,

        /** @var bool */
        public readonly bool $isGenerated,

        /** @var bool */
        public readonly bool $isAvailable,
    ) {}
}
