<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Nvl\Media\Enums\MediaResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use RuntimeException;

final class MediaNotReusableException extends RuntimeException implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve safe package response metadata. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('media', MediaResponseCode::MediaNotReusable, 422);
    }

    public static function privateAsset(string $mediaId): self
    {
        return new self("Media [{$mediaId}] is private and cannot be reused as a public asset.");
    }
}
