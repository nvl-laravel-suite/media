<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Exceptions\MediaUploadException;

/**
 * Safe default used until a consumer binds an object-storage adapter.
 */
final class UnsupportedMultipartUploadGateway implements MultipartUploadGateway
{
    public function initiate(array $session): void
    {
        $this->unsupported();
    }

    public function signPart(
        array $session,
        array $part,
    ): array {
        $this->unsupported();
    }

    public function complete(
        array $session,
        array $completion,
    ): array {
        $this->unsupported();
    }

    public function abort(array $session): void
    {
        $this->unsupported();
    }

    /**
     * Throw the stable unsupported-operation error.
     */
    private function unsupported(): never
    {
        throw new MediaUploadException(
            'Direct multipart uploads require a bound MultipartUploadGateway.',
        );
    }
}
