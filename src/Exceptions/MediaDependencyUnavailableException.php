<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Nvl\Media\Enums\MediaResponseCode;
use Nvl\Support\Exceptions\ExceptionResponse;

/** Signals missing optional dependencies for the selected built-in S3 capability. @api */
final class MediaDependencyUnavailableException extends MediaUploadException
{
    /** Supply safe configuration metadata while retaining the native exception constructor. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('media', MediaResponseCode::S3DependencyUnavailable, 500);
    }
}
