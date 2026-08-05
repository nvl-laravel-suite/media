<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use BackedEnum;
use InvalidArgumentException;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Defines the supported aspect-ratio and cropping strategy for an image variation.
 */
#[TypeScript]
enum ImageFit: string
{
    case Crop = 'crop';
    case Contain = 'contain';
    case Stretch = 'stretch';
    case Max = 'max';

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
            throw new InvalidArgumentException('Image fit must be crop, contain, stretch, or max.');
        }

        return self::from(mb_strtolower($value));
    }
}
