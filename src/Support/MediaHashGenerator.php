<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

/**
 * Generates cryptographically random opaque filenames for media storage.
 */
final class MediaHashGenerator
{
    /**
     * Generate a unique hashed filename preserving the original extension.
     *
     * @param  string  $filename  Original filename with extension
     * @return string Hashed filename (e.g., "a1b2c3d4e5f6...ext")
     */
    public static function generate(string $filename): string
    {
        return self::generateForExtension(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Generate an opaque filename for a canonical extension.
     */
    public static function generateForExtension(string $extension): string
    {
        $extension = mb_strtolower(ltrim($extension, '.'));

        return bin2hex(random_bytes(32)).'.'.$extension;
    }
}
