<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

/**
 * Names the three built-in image variation presets while allowing custom labels.
 *
 * @api
 */
enum ImagePreset: string
{
    case Thumbnail = 'thumb';
    case Small = 'small';
    case Medium = 'medium';
}
