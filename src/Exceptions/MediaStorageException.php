<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Nvl\Media\Enums\MediaResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use RuntimeException;

/** Expected failure to store or materialize a media object.
 * @api
 */
final class MediaStorageException extends RuntimeException implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Keep disk, path and provider diagnostics out of public context. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('media', MediaResponseCode::StorageUnavailable, 500);
    }
}
