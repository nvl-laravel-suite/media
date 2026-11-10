<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Nvl\Media\Tests\Fixtures\SerializesMediaProjection;

/** PublicMediaImage: public-safe image payload for storefront rendering. */
final class PublicMediaImage implements Arrayable, JsonSerializable
{
    use SerializesMediaProjection;

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
        public readonly array $sizes,
    ) {}
}
