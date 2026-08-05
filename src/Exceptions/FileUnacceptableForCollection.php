<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when an uploaded file does not meet the constraints defined by a media collection.
 */
class FileUnacceptableForCollection extends MediaException
{
    public function render(Request $request): ?JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return null;
    }
}
