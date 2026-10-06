<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\ClearOwnerMediaSlotContract;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Services\MediaOwnerSlotWorkflow;

/**
 * Clears one registered Media owner slot through the package lifecycle workflow.
 *
 * @api
 */
final readonly class ClearOwnerMediaSlotAction implements ClearOwnerMediaSlotContract
{
    public function __construct(
        private MediaOwnerSlotWorkflow $workflow,
    ) {}

    /**
     * Remove the current slot association when one exists.
     */
    public function execute(
        MediaActorData $actor,
        Model&HasNvlMedia $owner,
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
