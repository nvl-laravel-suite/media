<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Support\MediaAssociationSnapshot;

/**
 * Announces that a media record was attached to a generic owning model.
 */
final readonly class MediaAttached implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a media attachment event.
     *
     * @param  string  $mediaId  Affected media UUID
     * @param  array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string|null}  $association  Generic association owner metadata
     */
    public function __construct(
        public string $mediaId,
        public array $association,
    ) {}

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
}
