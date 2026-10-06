<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Models\Media;

/**
 * Renames the display filename for a media record.
 *
 * @api
 */
interface RenameMediaContract
{
    /**
     * Rename a media file's display filename.
     *
     * @param  Media|string  $media  Media instance or UUID
     * @param  string  $filename  New display filename
     * @return Media Fresh media model with translations and variations
     */
    public function execute(Media|string $media, string $filename): Media;
}
