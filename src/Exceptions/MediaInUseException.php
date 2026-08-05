<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reports a mutation that would violate a shared media asset's usage contract.
 */
final class MediaInUseException extends ConflictHttpException
{
    /**
     * Create an exception for deletion of a reused public asset.
     */
    public static function publicAsset(string $mediaId, int $associationCount): self
    {
        return new self(
            "Public media [{$mediaId}] is reused by {$associationCount} associations. ".
            'Detach it from an owner or explicitly force global deletion.',
        );
    }

    /**
     * Create an exception for making a reused public asset private.
     */
    public static function privateVisibility(string $mediaId): self
    {
        return new self(
            "Public media [{$mediaId}] cannot become private while it is reused by multiple associations.",
        );
    }
}
