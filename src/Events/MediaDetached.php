<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces that media associations were removed from generic owning models.
 *
 * @api
 */
final readonly class MediaDetached implements DomainEvent
{
    /**
     * Create a media detach event.
     *
     * @param  string  $mediaId  Affected media UUID
     * @param  array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string|null}>  $affectedAssociations  Removed generic association owner metadata
     */
    public function __construct(
        public string $mediaId,
        public array $affectedAssociations,
        public int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
