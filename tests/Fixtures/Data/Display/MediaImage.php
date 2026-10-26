<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Nvl\Media\Tests\Fixtures\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/** MediaImage: type-specific payload for image media and generated variations. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]

final class MediaImage extends Data
{
    use DataTransform;

    public function __construct(
        /** @var int|null */
        public readonly ?int $width,

        /** @var int|null */
        public readonly ?int $height,

        /** @var float|null */
        public readonly ?float $aspectRatio,

        /** @var string */
        public readonly string $originalUrl,

        /** @var string */
        public readonly string $previewUrl,

        /** @var array<int, MediaImageBreakpoint> */
        #[DataCollectionOf(MediaImageBreakpoint::class)]
        public readonly array $breakpoints,

        /** @var array<int, MediaImageSize> */
        #[DataCollectionOf(MediaImageSize::class)]
        public readonly array $sizes,

        /** @var array<int, MediaImageVariationPayload> */
        #[DataCollectionOf(MediaImageVariationPayload::class)]
        public readonly array $variations,
    ) {}
}
