<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use InvalidArgumentException;

/** MediaDiskResolver resolves the canonical default disk for Media operations. */
final class MediaDiskResolver
{
    /**
     * Resolve a disk name using centralized fallback policy.
     *
     * Fallback chain:
     * 1. Explicit disk (from collection definition or caller)
     * 2. Configured public/private disk when visibility is supplied
     * 3. nvl-media.disk (module-level default)
     * 4. filesystems.default (application-level default)
     * 5. 'local' (hardcoded safety net)
     *
     * @param  string|null  $disk  Explicit caller or slot disk
     * @param  bool|null  $isPublic  Upload visibility, or null for the generic default
     * @return string Effective disk name
     *
     * @throws InvalidArgumentException When a selected visibility default is invalid
     */
    public static function resolve(?string $disk = null, ?bool $isPublic = null): string
    {
        if (is_string($disk) && trim($disk) !== '') {
            return $disk;
        }

        if ($isPublic !== null) {
            $key = $isPublic ? 'nvl-media.public_disk' : 'nvl-media.private_disk';
            $selected = config($key);
            if ($selected !== null) {
                if (! is_string($selected) || trim($selected) === '') {
                    throw new InvalidArgumentException("{$key} must be null or a non-empty disk name.");
                }

                return $selected;
            }
        }

        $mediaDefault = config('nvl-media.disk');
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
