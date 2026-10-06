<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\Multipart\InitiateMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;

/**
 * Creates a server-owned multipart upload session before invoking the provider.
 *
 * @api
 */
interface InitiateMultipartUploadContract
{
    /** Execute the initiate multipart upload workflow. */
    public function execute(
        InitiateMultipartUploadData $data,
        MediaActorData $actor,
    ): MultipartUploadSessionData;
}
