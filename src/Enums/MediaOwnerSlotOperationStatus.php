<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lifecycle states for one durable owner-slot mutation claim.
 */
#[TypeScript]
enum MediaOwnerSlotOperationStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
