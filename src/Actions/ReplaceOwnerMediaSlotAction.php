<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Services\MediaOwnerSlotWorkflow;

/**
 * Replaces one registered Media owner slot through the package lifecycle workflow.
 */
final readonly class ReplaceOwnerMediaSlotAction
{
    public function __construct(
        private MediaOwnerSlotWorkflow $workflow,
    ) {}

    /**
     * Replace the current slot association with an authorized existing Media record.
     */
    public function execute(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        string $mediaId,
        ?string $idempotencyKey = null,
    ): MediaLibraryItem {
        return $this->workflow->replace(
            actor: $actor,
            owner: $owner,
            slot: $slot,
            mediaId: $mediaId,
            idempotencyKey: $idempotencyKey,
        );
    }
}
