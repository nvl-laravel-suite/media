<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Nvl\Media\Support\MediaQueueEnvelope;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Scalar-only audit fact for a committed catalog grant lifecycle change. *
 * @api
 */
final readonly class MediaCatalogGrantAudited implements DomainEvent, OwnershipQueuedJob
{
    private OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the authority captured when the fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    public function __construct(
        public string $operation,
        public string $grantId,
        public string $tenantId,
        public string $mediaId,
        public int $sourceRevision,
        public int $grantRevision,
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
