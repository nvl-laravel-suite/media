<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Nvl\Media\Services\MediaTemporaryFileRegistry;

/**
 * Represents a temporary local media file that callers must release after use.
 */
final class MediaTemporaryLocalFile
{
    private bool $released = false;

    public function __construct(
        private readonly string $path,
        private readonly ?MediaTemporaryFileRegistry $registry = null,
    ) {}

    /**
     * Return the absolute local path for the temporary file.
     *
     * @return string Absolute local path
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Release the temporary file when this lease owns cleanup.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if ($this->registry instanceof MediaTemporaryFileRegistry) {
            $this->registry->release($this->path);
        }
    }

    /**
     * Release owned temporary files when callers forget explicit cleanup.
     */
    public function __destruct()
    {
        $this->release();
    }
}
