<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Nvl\Support\Contracts\ResponseCode;

/**
 * Catalog of response codes for the Media module's Inertia (admin library) flows.
 *
 * Each case value is a short, opaque discriminator emitted by `MediaLibraryController`
 * in `flash.message`. The frontend `useAction` hook does NOT translate these — the
 * action declares its own `messages.success` / `messages.error` via typed `t()` calls.
 *
 * The JSON-API `MediaController` (at `routes/api.php`) is intentionally excluded from
 * this contract; its responses are consumed via direct fetch/axios, not Inertia flash.
 */
enum MediaResponseCode: string implements ResponseCode
{
    case Updated = 'updated';
    case Deleted = 'deleted';

    case OperationFailed = 'operation_failed';
}
