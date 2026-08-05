<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Announces that a media record's public-facing state changed.
 */
final readonly class MediaMutated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

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
    ) {}
}
