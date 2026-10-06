<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Support\Facades\DB;
use Nvl\Support\Logging\PackageLogger;
use Throwable;

/**
 * Applies MediaAdder's explicit local-source ownership contract.
 */
final class MediaOwnedSourceLifecycle
{
    /** Retain the stateless package diagnostic logger. */
    public function __construct(private PackageLogger $packageLogger) {}

    /**
     * Remove an explicitly owned local source only after the root commit.
     */
    public function deleteAfterCommit(string $path): void
    {
        DB::afterCommit(function () use ($path): void {
            try {
                if (is_file($path) && ! unlink($path)) {
                    $this->packageLogger->log('media', 'warning', 'nvl.media.owned_source_cleanup.rejected', [
                        'path' => $path,
                    ], 'normal');
                }
            } catch (Throwable $exception) {
                $this->packageLogger->log('media', 'error', 'nvl.media.owned_source_cleanup.failed', [
                    'path' => $path,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ], 'normal');
            }
        });
    }
}
