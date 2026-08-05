<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\MediaContentScanner;

/**
 * Default scanner used when the host has not configured a malware service.
 */
final class NullMediaContentScanner implements MediaContentScanner
{
    /**
     * Allow the upload without external malware scanning.
     */
    public function scan(UploadedFile $file): void {}
}
