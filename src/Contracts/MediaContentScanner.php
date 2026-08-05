<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Scans the exact materialized file that will be persisted by Media.
 */
interface MediaContentScanner
{
    /**
     * Scan a file and throw when it must not be stored.
     */
    public function scan(UploadedFile $file): void;
}
