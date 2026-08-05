<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies MediaAdder's explicit local-source ownership contract.
 */
final class MediaOwnedSourceLifecycle
{
    /**
     * Remove an explicitly owned local source only after the root commit.
     */
    public function deleteAfterCommit(string $path): void
    {
        DB::afterCommit(function () use ($path): void {
            try {
                if (is_file($path) && ! unlink($path)) {
                    Log::warning('Owned media source cleanup reported failure.', [
                        'path' => $path,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::error('Owned media source cleanup threw an exception.', [
                    'path' => $path,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }
}
