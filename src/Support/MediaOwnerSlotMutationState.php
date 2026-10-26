<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Nvl\Media\Models\Media;

/**
 * Tracks the durable commit boundary and optional immutable result of one slot mutation.
 */
final class MediaOwnerSlotMutationState
{
    private bool $committed = false;

    private ?Media $result = null;

    /**
     * Mark the Media root transaction as durably committed.
     */
    public function markCommitted(): void
    {
        $this->committed = true;
    }

    /**
     * Determine whether the Media mutation crossed its durable commit boundary.
     */
    public function committed(): bool
    {
        return $this->committed;
    }

    /**
     * Retain the immutable result assembled inside the Media transaction.
     */
    public function recordResult(Media $result): void
    {
        $this->result = $result;
    }

    /**
     * Return the newly assembled result when this attempt performed the mutation.
     */
    public function result(): ?Media
    {
        return $this->result;
    }
}
