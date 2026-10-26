<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

/**
 * Storage and reuse boundary for a media binary.
 *
 * @api
 */
enum MediaVisibility: string
{
    case Private = 'private';
    case Public = 'public';
}
