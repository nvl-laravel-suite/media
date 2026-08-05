<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\Multipart\CompletedMultipartObjectData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;

/**
 * Object-storage adapter for direct multipart uploads.
 */
interface MultipartUploadGateway
{
    /**
     * Initiate a provider multipart upload.
     */
    public function initiate(MultipartUploadSessionData $session): void;

    /**
     * Sign one part upload.
     */
    public function signPart(
        MultipartUploadSessionData $session,
        SignMultipartPartData $part,
    ): SignedMultipartPartData;

    /**
     * Complete and server-verify an uploaded object.
     */
    public function complete(
        MultipartUploadSessionData $session,
        CompleteMultipartUploadData $completion,
    ): CompletedMultipartObjectData;

    /**
     * Idempotently abort an unfinished provider upload, treating provider absence as success.
     */
    public function abort(MultipartUploadSessionData $session): void;
}
