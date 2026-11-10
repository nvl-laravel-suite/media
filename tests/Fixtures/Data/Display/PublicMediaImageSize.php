<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Nvl\Media\Tests\Fixtures\SerializesMediaProjection;

/** PublicMediaImageSize: public-safe available image source for responsive rendering. */
final class PublicMediaImageSize implements Arrayable, JsonSerializable
{
    use SerializesMediaProjection;

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

        /** @var string */
        public readonly string $url,

        /** @var string|null */
        public readonly ?string $format,

        /** @var int|null */
        public readonly ?int $size,

        /** @var bool */
        public readonly bool $isGenerated,
    ) {}
}
