<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Nvl\Media\Exceptions\MediaUploadException;

/** Consumer transport adapter for an explicitly supplied request. */
final class RequestMediaSource
{
    /**
     * Extract an application-owned request upload without claiming ownership.
     *
     * @throws MediaUploadException
     */
    public static function fromRequest(Request $request, string $key): UploadedFile
    {
        $file = $request->file($key);

        if (! $file instanceof UploadedFile) {
            throw new MediaUploadException("No file found in request for key [{$key}].");
        }

        return $file;
    }
}
