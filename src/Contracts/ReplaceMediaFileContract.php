<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Models\Media;
use Throwable;

/**
 * Replaces a media record's original file while preserving associations.
 *
 * @api
 */
interface ReplaceMediaFileContract
{
    /**
     * Replace the original file and regenerate variations for the committed media state.
     *
     * @throws Throwable
     */
    public function execute(Media|string $media, UploadedFile $file): Media;
}
