<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use RuntimeException;

final class MediaNotReusableException extends RuntimeException
{
    public static function privateAsset(string $mediaId): self
    {
        return new self("Media [{$mediaId}] is private and cannot be reused as a public asset.");
    }
}
