<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Announces that media associations were removed from generic owning models.
 */
final readonly class MediaDetached implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a media detach event.
     *
     * @param  string  $mediaId  Affected media UUID
     * @param  array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string|null}>  $affectedAssociations  Removed generic association owner metadata
     */
    public function __construct(
        public string $mediaId,
        public array $affectedAssociations,
    ) {}
}
