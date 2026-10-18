<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Nvl\Media\Support\MediaQueueEnvelope;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Announces that a media record's public-facing state changed.
 *
 * @api
 */
final readonly class MediaMutated implements DomainEvent, OwnershipQueuedJob
{
    private OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the authority captured when the fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /**
     * Create a media mutation event.
     *
     * @param  string  $mediaId  Affected media UUID
     * @param  string  $mutation  Generic mutation name
     * @param  array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string|null}>  $affectedAssociations  Association snapshots when they cannot be queried after mutation
     */
    public function __construct(
        public string $mediaId,
        public string $mutation,
        public array $affectedAssociations = [],
        public int $schemaVersion = 1,
    ) {
        $this->ownershipEnvelope = MediaQueueEnvelope::ownership();
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
