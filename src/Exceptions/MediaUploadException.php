<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a media file cannot be stored on the target disk during upload.
 *
 * @api
 */
class MediaUploadException extends MediaException
{
    public function render(Request $request): ?JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return null;
    }
}
