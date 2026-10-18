<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Locks canonical attachment targets inside native persistence, including quiet writes. @internal */
final readonly class MediaAssociationOwnership
{
    public function __construct(private MediaOwnershipParentResolver $parents) {}

    public function assertTarget(MediaAssociation $association): void
    {
        if (! MediaOwnership::declared()) {
            return;
        }
        $attributes = $association->getAttributes();
        $type = $attributes['associable_type'] ?? null;
        $id = $attributes['associable_id'] ?? null;
        if (! is_string($type) || (! is_int($id) && ! is_string($id))
            || ! $this->parents->resolveIdentity($type, $id) instanceof HasNvlMedia) {
            throw new OwnershipViolation('Media attachment targets require canonical Media-capable host identities.');
        }
    }
}
