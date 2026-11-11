<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

/**
 * Generates cryptographically random opaque filenames for media storage.
 */
final class MediaHashGenerator
{
    /**
     * Generate an opaque filename for a canonical extension.
     */
    public static function generateForExtension(string $extension): string
    {
        $extension = mb_strtolower(ltrim($extension, '.'));

        return bin2hex(random_bytes(32)).'.'.$extension;
    }
}
