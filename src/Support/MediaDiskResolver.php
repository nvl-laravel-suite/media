<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

/** MediaDiskResolver resolves the canonical default disk for Media operations. */
final class MediaDiskResolver
{
    /**
     * Resolve a disk name using centralized fallback policy.
     *
     * Fallback chain:
     * 1. Explicit disk (from collection definition or caller)
     * 2. media.disk (module-level default)
     * 3. filesystems.default (application-level default)
     * 4. 'local' (hardcoded safety net — should never be reached)
     */
    public static function resolve(?string $disk = null): string
    {
        if (is_string($disk) && trim($disk) !== '') {
            return $disk;
        }

        $mediaDefault = config('media.disk');
        if (is_string($mediaDefault) && trim($mediaDefault) !== '') {
            return $mediaDefault;
        }

        $filesystemDefault = config('filesystems.default');
        if (is_string($filesystemDefault) && trim($filesystemDefault) !== '') {
            return $filesystemDefault;
        }

        return 'local';
    }
}
