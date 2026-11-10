<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Nvl\Media\Tests\Fixtures\SerializesMediaProjection;

/** MediaImageBreakpoint: configured responsive image breakpoint exposed to clients. */
final class MediaImageBreakpoint implements Arrayable, JsonSerializable
{
    use SerializesMediaProjection;

    public function __construct(
        /** @var string */
        public readonly string $label,

        /** @var int|null */
        public readonly ?int $width,

        /** @var int|null */
        public readonly ?int $height,

        /** @var string|null */
        public readonly ?string $format,

        /** @var int|null */
        public readonly ?int $quality,

        /** @var bool */
        public readonly bool $enabled,
    ) {}
}
