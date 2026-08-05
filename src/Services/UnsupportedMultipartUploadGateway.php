<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Data\Multipart\CompletedMultipartObjectData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;
use Nvl\Media\Exceptions\MediaUploadException;

/**
 * Safe default used until a consumer binds an object-storage adapter.
 */
final class UnsupportedMultipartUploadGateway implements MultipartUploadGateway
{
    public function initiate(MultipartUploadSessionData $session): void
    {
        $this->unsupported();
    }

    public function signPart(
        MultipartUploadSessionData $session,
        SignMultipartPartData $part,
    ): SignedMultipartPartData {
        $this->unsupported();
    }

    public function complete(
        MultipartUploadSessionData $session,
        CompleteMultipartUploadData $completion,
    ): CompletedMultipartObjectData {
        $this->unsupported();
    }

    public function abort(MultipartUploadSessionData $session): void
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
