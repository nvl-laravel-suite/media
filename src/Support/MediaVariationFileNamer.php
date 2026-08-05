<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use InvalidArgumentException;

/**
 * Produces deterministic, object-storage-safe filenames for image variations.
 */
final class MediaVariationFileNamer
{
    /**
     * Build a variation filename from its immutable source and output metadata.
     */
    public static function make(
        string $sourceFilename,
        string $label,
        int $width,
        int $height,
        string $extension,
    ): string {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $label) !== 1) {
            throw new InvalidArgumentException("Variation label [{$label}] is not object-key safe.");
        }

        $basename = pathinfo($sourceFilename, PATHINFO_FILENAME);
        $pattern = MediaConfiguration::string(
            'media.variation_naming.pattern',
            '{basename}-{label}.{extension}',
        );
        $filename = strtr($pattern, [
            '{basename}' => $basename,
            '{label}' => $label,
            '{width}' => (string) $width,
            '{height}' => (string) $height,
            '{extension}' => mb_strtolower($extension),
        ]);

        if ($filename !== basename($filename)
            || str_contains($filename, '..')
            || str_contains($filename, "\0")
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,254}$/', $filename) !== 1) {
            throw new InvalidArgumentException('The configured media variation filename pattern produced an unsafe object key.');
        }

        return $filename;
    }

    private function __construct() {}
}
