<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

/**
 * Explicit transport and access-capability choices for media URLs.
 *
 * @api
 */
enum MediaDeliveryMode: string
{
    case Direct = 'direct';
    case Application = 'application';
    case SignedApplication = 'signed_application';
    case TemporaryStorage = 'temporary_storage';
}
