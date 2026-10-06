<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Throwable;

/**
 * Relocates one media binary and its current variations to another disk.
 *
 * @api
 */
interface RelocateMediaContract
{
    /**
     * Copy, verify, and atomically swap one media record to a different disk.
     *
     * @throws MediaUploadException
     * @throws Throwable
     */
    public function execute(
        Media|string $media,
        string $disk,
        MediaVisibility $visibility,
        ?int $expectedRevision = null,
    ): Media;
}
