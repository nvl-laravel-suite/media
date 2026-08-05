<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Storage and reuse boundary for a media binary.
 */
#[TypeScript]
enum MediaVisibility: string
{
    case Private = 'private';
    case Public = 'public';
}
