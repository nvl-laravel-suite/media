<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Nvl\Media\Tests\Fixtures\SerializesMediaProjection;

/** MediaImage: type-specific payload for image media and generated variations. */
final class MediaImage implements Arrayable, JsonSerializable
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
        public readonly string $originalUrl,

        /** @var string */
        public readonly string $previewUrl,

        /** @var array<int, MediaImageBreakpoint> */
        public readonly array $breakpoints,

        /** @var array<int, MediaImageSize> */
        public readonly array $sizes,

        /** @var array<int, MediaImageVariationPayload> */
        public readonly array $variations,
    ) {}
}
