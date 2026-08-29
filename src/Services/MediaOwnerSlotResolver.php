<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Slots\MediaSlot;

/**
 * Resolves persisted Media owners, registered single-file slots, and their exact associations.
 */
final readonly class MediaOwnerSlotResolver
{
    /**
     * Resolve and validate a package-owned single-file slot.
     */
    public function slot(Model&HasMedia $owner, string $slot): MediaSlot
    {
        $this->ownerIdentity($owner);

        $slot = trim($slot);

        if ($slot === '' || mb_strlen($slot) > 50) {
            throw new InvalidArgumentException(
                'A Media owner-slot name must be non-empty and at most 50 characters.',
            );
        }

        $resolved = $owner->getMediaSlot($slot);

        if (! $resolved instanceof MediaSlot) {
            throw new InvalidArgumentException(
                "Media slot [{$slot}] is not registered for owner [{$owner->getMorphClass()}].",
            );
        }

        if (! $resolved->isSingleFile || $resolved->slotSizeLimit !== 1) {
            throw new InvalidArgumentException(
                "Media owner-slot workflows require single-file slot [{$slot}].",
            );
        }

        return $resolved;
    }

    /**
     * Resolve the canonical persisted owner identity.
     *
     * @return array{type: string, id: string}
     */
    public function ownerIdentity(Model&HasMedia $owner): array
    {
        $ownerId = $owner->getKey();

        if (! $owner->exists || (! is_int($ownerId) && ! is_string($ownerId))) {
            throw new InvalidArgumentException(
                'Media owner-slot workflows require a persisted owner with a scalar key.',
            );
        }

        $ownerType = trim($owner->getMorphClass());

        if ($ownerType === '' || (string) $ownerId === '') {
            throw new InvalidArgumentException(
                'Media owner-slot workflows require a canonical owner type and identifier.',
            );
        }

        return [
            'type' => $ownerType,
            'id' => (string) $ownerId,
        ];
    }

    /**
     * Load the exact association rows in one owner slot.
     *
     * @return Collection<int, MediaAssociation>
     */
    public function associations(
        Model&HasMedia $owner,
        string $slot,
        bool $lockForUpdate = false,
    ): Collection {
        $identity = $this->ownerIdentity($owner);
        $query = MediaAssociation::query()
            ->where('associable_type', $identity['type'])
            ->where('associable_id', $identity['id'])
            ->where('collection', $slot)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Resolve the only association in a single-file slot.
     */
    public function currentAssociation(
        Model&HasMedia $owner,
        string $slot,
        bool $lockForUpdate = false,
    ): ?MediaAssociation {
        $associations = $this->associations($owner, $slot, $lockForUpdate);

        if ($associations->count() > 1) {
            throw new LogicException(
                "Single-file Media slot [{$slot}] contains multiple associations.",
            );
        }

        return $associations->first();
    }
}
