<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Models\Media;

/**
 * Completes and recovers a persisted direct-upload session idempotently.
 *
 * @api
 */
interface CompleteMultipartUploadContract
{
    /** Execute the complete multipart upload workflow. */
    public function execute(
        CompleteMultipartUploadData $completion,
        MediaActorData $actor,
    ): Media;
}
