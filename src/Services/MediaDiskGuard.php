<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Validation\ValidationException;
use Nvl\Media\Support\MediaDiskResolver;

/**
 * Guards Media disk selection against the configured allowlist.
 *
 * Acts as the single authority for deciding whether a requested filesystem
 * disk may be used by user-facing Media write operations.
 */
final class MediaDiskGuard
{
    /**
     * Assert that the given disk may be used by Media uploads and migrations.
     *
     * @param  string  $disk  Filesystem disk name to evaluate
     *
     * @throws ValidationException When the disk is not allowed for Media
     */
    public function assertAllowed(string $disk): void
    {
        if ($this->isAllowed($disk)) {
            return;
        }

        throw ValidationException::withMessages([
            'disk' => ["The disk [{$disk}] is not allowed for media operations."],
        ]);
    }

    /**
     * Resolve a requested disk through Media defaults and assert it is allowed.
     *
     * @param  string|null  $disk  Explicit disk request, or null for the Media default
     * @return string Resolved and allowed Media disk name
     *
     * @throws ValidationException When the resolved disk is not allowed for Media
     */
    public function resolveAllowed(?string $disk = null): string
    {
        $resolved = MediaDiskResolver::resolve($disk);

        $this->assertAllowed($resolved);

        return $resolved;
    }

    /**
     * Determine whether the given disk may be used by Media.
     *
     * @param  string  $disk  Filesystem disk name to evaluate
     * @return bool True when the disk is present in the effective allowlist
     */
    public function isAllowed(string $disk): bool
    {
        return in_array($disk, $this->allowedDisks(), true);
    }

    /**
     * Resolve the effective Media disk allowlist.
     *
     * @return list<string> Allowed disk names including the current Media default
     */
    public function allowedDisks(): array
    {
        $configured = config('media.allowed_disks', []);
        $allowed = is_array($configured)
            ? array_values(array_filter($configured, static fn (mixed $disk): bool => is_string($disk) && $disk !== ''))
            : [];

        $defaultDisk = MediaDiskResolver::resolve();
        if (! in_array($defaultDisk, $allowed, true)) {
            $allowed[] = $defaultDisk;
        }

        return array_values(array_unique($allowed));
    }
}
