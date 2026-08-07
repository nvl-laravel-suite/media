<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures {
    /**
     * Controls native filesystem failure paths for GenerateImageVariationAction tests.
     */
    final class GenerateImageVariationFilesystemFake
    {
        public static bool $failTempnam = false;

        public static bool $failRename = false;

        public static function reset(): void
        {
            self::$failTempnam = false;
            self::$failRename = false;
        }
    }
}

namespace Nvl\Media\Actions {
    use Nvl\Media\Tests\Fixtures\GenerateImageVariationFilesystemFake;

    function tempnam(string $directory, string $prefix): string|false
    {
        return GenerateImageVariationFilesystemFake::$failTempnam
            ? false
            : \tempnam($directory, $prefix);
    }

    function rename(string $from, string $to): bool
    {
        return GenerateImageVariationFilesystemFake::$failRename
            ? false
            : \rename($from, $to);
    }
}
