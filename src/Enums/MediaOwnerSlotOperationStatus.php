<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

/**
 * Lifecycle states for one durable owner-slot mutation claim.
 */
enum MediaOwnerSlotOperationStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
