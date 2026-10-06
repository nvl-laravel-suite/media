<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces that a new media row and its physical object were created successfully.
 *
 * @api
 */
final class MediaUploaded implements DomainEvent
{
    /**
     * Create the committed media upload event.
     */
    public function __construct(public readonly string $mediaId, public readonly int $schemaVersion = 1) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
