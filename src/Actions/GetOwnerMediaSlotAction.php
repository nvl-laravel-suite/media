<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Services\MediaOwnerSlotWorkflow;

/**
 * Returns the authorized projection for one registered Media owner slot.
 */
final readonly class GetOwnerMediaSlotAction
{
    public function __construct(
        private MediaOwnerSlotWorkflow $workflow,
    ) {}

    /**
     * Read one registered single-file slot without exposing persistence models.
     */
    public function execute(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
    ): ?MediaLibraryItem {
        return $this->workflow->get($actor, $owner, $slot);
    }
}
