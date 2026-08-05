<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Foundation\Application;

/**
 * Tracks request-scoped temporary media files for guaranteed termination cleanup.
 */
final class MediaTemporaryFileRegistry
{
    /** @var array<string, true> */
    private array $paths = [];

    private bool $registered = false;

    public function __construct(
        private readonly Application $application,
    ) {}

    /**
     * Track a temporary path for termination cleanup.
     *
     * @param  string  $path  Absolute temporary path
     */
    public function track(string $path): void
    {
        $this->paths[$path] = true;
        $this->registerCleanup();
    }

    /**
     * Determine whether the package owns a temporary path.
     */
    public function owns(string $path): bool
    {
        return isset($this->paths[$path]);
    }

    /**
     * Return the number of package-owned temporary files awaiting release.
     */
    public function count(): int
    {
        return count($this->paths);
    }

    /**
     * Release one tracked temporary path immediately.
     *
     * @param  string  $path  Absolute temporary path
     */
    public function release(string $path): void
    {
        unset($this->paths[$path]);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Release every tracked temporary path.
     */
    public function releaseAll(): void
    {
        foreach (array_keys($this->paths) as $path) {
            $this->release($path);
        }

        $this->paths = [];
        $this->registered = false;
    }

    /**
     * Register the application termination cleanup callback once.
     */
    private function registerCleanup(): void
    {
        if ($this->registered) {
            return;
        }

        $this->application->terminating(function (): void {
            $this->releaseAll();
        });

        $this->registered = true;
    }
}
