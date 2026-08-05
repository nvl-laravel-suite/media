<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Persisted state of a direct multipart upload session.
 */
#[TypeScript]
enum MediaMultipartStatus: string
{
    case Initiated = 'initiated';
    case Completing = 'completing';
    case Completed = 'completed';
    case Aborted = 'aborted';
    case Expired = 'expired';
    case Failed = 'failed';
}
