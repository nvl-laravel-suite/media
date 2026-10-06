<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Nvl\Media\Enums\MediaResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use RuntimeException;

/** Explicit unsupported authorization or storage mode for bounded Media reads.
 *
 * @api
 */
final class MediaBatchReadException extends RuntimeException implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve safe package response metadata. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('media', MediaResponseCode::BatchReadUnavailable, 500);
    }
}
