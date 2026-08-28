<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Durable mutation types supported by the owner-slot workflow ledger.
 */
#[TypeScript]
enum MediaOwnerSlotOperationType: string
{
    case Replace = 'replace';
    case Clear = 'clear';
    case Copy = 'copy';
}
