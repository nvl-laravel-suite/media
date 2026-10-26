<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

/**
 * Stable authorization abilities for media operations.
 *
 * @api
 */
enum MediaAbility: string
{
    case List = 'list';
    case ListAll = 'list_all';
    case View = 'view';
    case Download = 'download';
    case Upload = 'upload';
    case Associate = 'associate';
    case Mutate = 'mutate';
    case Delete = 'delete';
    case Reuse = 'reuse';
    case ManageStaging = 'manage_staging';
}
