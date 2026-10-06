<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Exception;
use Nvl\Media\Enums\MediaResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;

/**
 * @api
 * Base exception for the Media domain.
 */
abstract class MediaException extends Exception implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve the declared safe failure for this native hierarchy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return match (static::class) {
            FileUnacceptableForCollection::class => new ExceptionResponse('media', MediaResponseCode::FileUnacceptableForCollection, 422),
            ConversionFailedException::class => new ExceptionResponse('media', MediaResponseCode::ConversionFailed, 500),
            DiskNotDefinedException::class => new ExceptionResponse('media', MediaResponseCode::DiskNotDefined, 500),
            MediaUploadException::class => new ExceptionResponse('media', MediaResponseCode::MediaUploadFailed, 422),
            MediaInUseException::class => new ExceptionResponse('media', MediaResponseCode::MediaInUse, 409),
            default => new ExceptionResponse('media', MediaResponseCode::OperationFailed),
        };
    }
}
