<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Validation\ValidationException;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Support\MediaDiskResolver;

/**
 * Guards Media disk selection against the configured allowlist.
 *
 * Acts as the single authority for deciding whether a requested filesystem
 * disk may be used by user-facing Media write operations.
 *
 * @internal
 */
final class MediaDiskGuard
{
    /** Apply the canonical disk delivery capability policy to visibility-aware writes. */
    public function __construct(private readonly MediaDeliveryPolicy $deliveryPolicy) {}

    /**
     * Assert that the given disk may be used by Media uploads and migrations.
     *
     * @param  string  $disk  Filesystem disk name to evaluate
     * @param  MediaVisibility|null  $visibility  Actual object visibility when writing files
     *
     * @throws ValidationException When the disk is not allowed for Media
     */
    public function assertAllowed(string $disk, ?MediaVisibility $visibility = null): void
    {
        if (! $this->isAllowed($disk)) {
            throw ValidationException::withMessages([
                'disk' => ["The disk [{$disk}] is not allowed for media operations."],
            ]);
        }

        if ($visibility !== null) {
            $this->deliveryPolicy->assertVisibility($disk, $visibility);
        }
    }

    /**
     * Resolve a requested disk through Media defaults and assert it is allowed.
     *
     * @param  string|null  $disk  Explicit disk request, or null for the Media default
     * @param  bool|null  $isPublic  Upload visibility when selecting a public/private default
     * @return string Resolved and allowed Media disk name
     *
     * @throws ValidationException When the resolved disk is not allowed for Media
     */
    public function resolveAllowed(?string $disk = null, ?bool $isPublic = null): string
    {
        $resolved = MediaDiskResolver::resolve($disk, $isPublic);

        $this->assertAllowed($resolved, $isPublic === null ? null : ($isPublic ? MediaVisibility::Public : MediaVisibility::Private));

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
        $configured = config('nvl-media.allowed_disks', []);
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
