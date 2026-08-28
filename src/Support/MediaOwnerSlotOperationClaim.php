<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

/**
 * Immutable result of claiming an idempotent owner-slot mutation.
 */
final readonly class MediaOwnerSlotOperationClaim
{
    public function __construct(
        public string $operationId,
        public string $requestHash,
        public bool $replayed,
        public ?string $resultMediaId,
    ) {}
}
