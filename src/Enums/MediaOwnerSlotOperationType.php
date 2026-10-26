<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

/**
 * Durable mutation types supported by the owner-slot workflow ledger.
 */
enum MediaOwnerSlotOperationType: string
{
    case Replace = 'replace';
    case Clear = 'clear';
    case Copy = 'copy';
}
