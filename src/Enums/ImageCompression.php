<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use BackedEnum;
use InvalidArgumentException;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Defines whether an image output favors lossy compression or lossless fidelity.
 */
#[TypeScript]
enum ImageCompression: string
{
    case Lossy = 'lossy';
    case Lossless = 'lossless';

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
            throw new InvalidArgumentException('Image compression must be lossy or lossless.');
        }

        return self::from(mb_strtolower($value));
    }
}
