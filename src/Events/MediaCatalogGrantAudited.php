<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Scalar-only audit fact for a committed catalog grant lifecycle change. */
final readonly class MediaCatalogGrantAudited implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $operation,
        public string $grantId,
        public string $tenantId,
        public string $mediaId,
        public int $sourceRevision,
        public int $grantRevision,
    ) {}
}
