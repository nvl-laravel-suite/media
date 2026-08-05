<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when an image conversion process fails during processing or storage.
 */
class ConversionFailedException extends MediaException
{
    public function render(Request $request): ?JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 500);
        }

        return null;
    }
}
