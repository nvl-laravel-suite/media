<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Exceptions\MediaUploadException;

/**
 * Validates scanner configuration before accepting an upload.
 */
final readonly class MediaScannerPolicy
{
    /**
     * Create the scanner policy.
     */
    public function __construct(
        private MediaContentScanner $scanner,
    ) {}

    /**
     * Reject a required no-op scanner unless the consumer explicitly allows it.
     */
    public function assertReady(): void
    {
        $required = (bool) config('media.scanner.required', false);
        $allowNoop = (bool) config('media.scanner.allow_noop', false);

        if ($required && $this->scanner instanceof NullMediaContentScanner && ! $allowNoop) {
            throw new MediaUploadException(
                'Media scanning is required but no production MediaContentScanner is bound.',
            );
        }
    }
}
