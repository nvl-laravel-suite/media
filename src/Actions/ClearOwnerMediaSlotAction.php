<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Services\MediaOwnerSlotWorkflow;

/**
 * Clears one registered Media owner slot through the package lifecycle workflow.
 */
final readonly class ClearOwnerMediaSlotAction
{
    public function __construct(
        private MediaOwnerSlotWorkflow $workflow,
    ) {}

    /**
     * Remove the current slot association when one exists.
     */
    public function execute(
        MediaActorData $actor,
        Model&HasMedia $owner,
        string $slot,
        ?string $idempotencyKey = null,
    ): void {
        $this->workflow->clear(
            actor: $actor,
            owner: $owner,
            slot: $slot,
            idempotencyKey: $idempotencyKey,
        );
    }
}
