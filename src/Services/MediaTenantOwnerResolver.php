<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Ownership\MediaOwnershipParentResolver;

/** @deprecated Resolve Media hosts through the neutral package ownership service. */
final readonly class MediaTenantOwnerResolver
{
    public function __construct(private MediaOwnershipParentResolver $owners) {}

    public function resolve(Model $owner, bool $lock = false): Model
    {
        return $this->owners->resolve($owner, $lock);
    }
}
