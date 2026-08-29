<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Services\MediaOwnerSlotCopyWorkflow;

/**
 * Copies one authorized Media source into a registered owner slot.
 */
final readonly class CopyOwnerMediaSlotAction
{
    public function __construct(
        private MediaOwnerSlotCopyWorkflow $workflow,
    ) {}

    /**
     * Copy verified source bytes through canonical destination ingestion.
     */
    public function execute(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        string $sourceMediaId,
        ?string $idempotencyKey = null,
    ): MediaLibraryItem {
        return $this->workflow->copy(
            actor: $actor,
            owner: $owner,
            slot: $slot,
            sourceMediaId: $sourceMediaId,
            idempotencyKey: $idempotencyKey,
        );
    }
}
