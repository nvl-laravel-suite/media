<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Nvl\Media\Enums\MediaResponseCode;
use Nvl\Support\Exceptions\ExceptionResponse;

/**
 * @api

 * Reports a mutation that would violate a shared media asset's usage contract.
 */
final class MediaInUseException extends MediaException
{
    /** @var array<string, mixed> */
    private array $safeContext = [];

    /** Resolve conflict metadata without exposing diagnostic copy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('media', MediaResponseCode::MediaInUse, 409, $this->safeContext);
    }

    /**
     * Create an exception for deletion of a reused public asset.
     */
    public static function publicAsset(string $mediaId, int $associationCount): self
    {
        $exception = new self(
            "Public media [{$mediaId}] is reused by {$associationCount} associations. ".
            'Detach it from an owner or explicitly force global deletion.',
        );
        $exception->safeContext = ['media_id' => $mediaId, 'association_count' => $associationCount];

        return $exception;
    }

    /**
     * Create an exception for making a reused public asset private.
     */
    public static function privateVisibility(string $mediaId): self
    {
        $exception = new self(
            "Public media [{$mediaId}] cannot become private while it is reused by multiple associations.",
        );
        $exception->safeContext = ['media_id' => $mediaId];

        return $exception;
    }
}
