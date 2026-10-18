<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Nvl\Media\Support\MediaQueueEnvelope;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Announces that a new media row and its physical object were created successfully.
 *
 * @api
 */
final class MediaUploaded implements DomainEvent, OwnershipQueuedJob
{
    private OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the authority captured when the fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /**
     * Create the committed media upload event.
     */
    public function __construct(public readonly string $mediaId, public readonly int $schemaVersion = 1)
    {
        $this->ownershipEnvelope = MediaQueueEnvelope::ownership();
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
