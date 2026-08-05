<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use BackedEnum;
use InvalidArgumentException;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Defines image formats that the package may produce through Spatie Image.
 */
#[TypeScript]
enum ImageFormat: string
{
    case Jpeg = 'jpg';
    case Png = 'png';
    case Gif = 'gif';
    case Webp = 'webp';
    case Avif = 'avif';

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

        if (! is_string($value)) {
            throw new InvalidArgumentException('Image format must be a supported string or ImageFormat enum.');
        }

        $normalized = mb_strtolower($value) === 'jpeg' ? self::Jpeg->value : mb_strtolower($value);
        $format = self::tryFrom($normalized);

        if (! $format instanceof self) {
            throw new InvalidArgumentException("Unsupported image output format [{$value}].");
        }

        return $format;
    }

    /**
     * Return the standard MIME type for this image format.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Gif => 'image/gif',
            self::Webp => 'image/webp',
            self::Avif => 'image/avif',
        };
    }
}
