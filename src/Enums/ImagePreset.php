<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Names the three built-in image variation presets while allowing custom labels.
 */
#[TypeScript]
enum ImagePreset: string
{
    case Thumbnail = 'thumb';
    case Small = 'small';
    case Medium = 'medium';
}
