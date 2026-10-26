<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Nvl\Media\Tests\Fixtures\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/** PublicMediaImage: public-safe image payload for storefront rendering. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]

final class PublicMediaImage extends Data
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
        public readonly string $src,

        /** @var string */
        public readonly string $previewUrl,

        /** @var string|null */
        public readonly ?string $srcSet,

        /** @var array<int, PublicMediaImageSize> */
        #[DataCollectionOf(PublicMediaImageSize::class)]
        public readonly array $sizes,
    ) {}
}
