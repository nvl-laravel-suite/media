<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

/**
 * Immutable result of claiming an idempotent owner-slot mutation.
 */
final readonly class MediaOwnerSlotOperationClaim
{
    /**
     * @param  array<string, mixed>|null  $resultPayload
     */
    public function __construct(
        public string $operationId,
        public string $requestHash,
        public bool $replayed,
        public ?string $resultMediaId,
        public ?array $resultPayload = null,
    ) {}
}
