<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Nvl\Media\Tests\Fixtures\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/** MediaImageBreakpoint: configured responsive image breakpoint exposed to clients. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]

final class MediaImageBreakpoint extends Data
{
    use DataTransform;

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
