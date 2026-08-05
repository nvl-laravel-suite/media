<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use BackedEnum;
use InvalidArgumentException;
use Spatie\Image\Enums\ImageDriver;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Selects the Spatie Image backend used for local image processing.
 */
#[TypeScript]
enum MediaImageDriver: string
{
    case Gd = 'gd';
    case Imagick = 'imagick';
    case Vips = 'vips';

    /**
     * Resolve an enum or scalar configuration value.
     *
     * @throws InvalidArgumentException When the configured value is unsupported
     */
    public static function resolve(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value) || self::tryFrom(mb_strtolower($value)) === null) {
            throw new InvalidArgumentException('Media image driver must be gd, imagick, or vips.');
        }

        return self::from(mb_strtolower($value));
    }

    /**
     * Return the corresponding Spatie Image driver enum.
     */
    public function spatieDriver(): ImageDriver
    {
        return match ($this) {
            self::Gd => ImageDriver::Gd,
            self::Imagick => ImageDriver::Imagick,
            self::Vips => ImageDriver::Vips,
        };
    }
}
