<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Support\MediaAssociationSnapshot;
use Nvl\Media\Support\MediaQueueEnvelope;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Announces that a media record was attached to a generic owning model.
 *
 * @api
 */
final readonly class MediaAttached implements DomainEvent, OwnershipQueuedJob
{
    private OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the authority captured when the fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /**
     * Create a media attachment event.
     *
     * @param  string  $mediaId  Affected media UUID
     * @param  array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string|null}  $association  Generic association owner metadata
     */
    public function __construct(
        public string $mediaId,
        public array $association,
        public int $schemaVersion = 1,
    ) {
        $this->ownershipEnvelope = MediaQueueEnvelope::ownership();
    }

    /**
     * Create the event from a persisted association model.
     *
     * @param  MediaAssociation  $association  Persisted media association
     * @return self Event instance
     */
    public static function fromAssociation(MediaAssociation $association): self
    {
        return new self(
            mediaId: $association->media_id,
            association: MediaAssociationSnapshot::fromAssociation($association),
        );
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
