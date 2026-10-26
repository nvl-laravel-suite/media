<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Enums\SystemActor;

/**
 * Host policy for issuing or revoking central-copy capabilities in the current admitted owner.
 *
 * @api
 */
interface MediaCatalogAccess
{
    public function assertCanGrant(Authenticatable|SystemActor $actor, string $sourceId, int $revision): void;

    public function assertCanRevoke(Authenticatable|SystemActor $actor, string $grantId): void;
}
