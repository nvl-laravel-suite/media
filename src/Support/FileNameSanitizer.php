<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Illuminate\Support\Str;

/**
 * Sanitizes filenames for safe filesystem storage.
 *
 * Strips unsafe characters, transliterates non-ASCII when configured,
 * and enforces the 255-byte filesystem limit by truncating with a hash suffix.
 */
final class FileNameSanitizer
{
    /**
     * Sanitize a filename for safe storage on disk.
     *
     * @param  string  $filename  Original filename including extension
     * @return string Sanitized filename safe for all major filesystems
     */
    public static function sanitize(string $filename): string
    {
        if (! Str::isAscii($filename) && config('media.transliterate', false)) {
            $filename = Str::transliterate($filename);
        }

        $filename = Str::replace(['_', ' ', '--'], ['-', '-', '-'], trim($filename));
        $filename = preg_replace('/[!@#$^*"\'\\+:;\\\\\/,]/', '', $filename) ?? $filename;

        // Enforce filesystem max filename length (255 bytes)
        if (mb_strlen($filename) > 255) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $hash = substr(md5($name), 0, 14);
            $max_name_length = 255 - mb_strlen($extension) - mb_strlen($hash) - 2; // dot + underscore
            $filename = mb_substr($name, 0, max(1, $max_name_length)).'_'.$hash.'.'.$extension;
        }

        return $filename;
    }
}
