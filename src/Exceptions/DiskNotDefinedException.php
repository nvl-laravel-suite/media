<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when an operation references a storage disk that is not configured.
 */
class DiskNotDefinedException extends MediaException
{
    public function render(Request $request): ?JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 500);
        }

        return null;
    }
}
