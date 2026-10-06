<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Nvl\Support\Contracts\DomainEvent;

/** Scalar-only audit fact for a committed catalog grant lifecycle change. *
 * @api
 */
final readonly class MediaCatalogGrantAudited implements DomainEvent
{
    public function __construct(
        public string $operation,
        public string $grantId,
        public string $tenantId,
        public string $mediaId,
        public int $sourceRevision,
        public int $grantRevision,
        public int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
