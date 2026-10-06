<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\MediaActorData;

/**
 * Idempotently aborts an actor-owned persisted multipart session.
 *
 * @api
 */
interface AbortMultipartUploadContract
{
    /** Execute the abort multipart upload workflow. */
    public function execute(
        string $uploadId,
        MediaActorData $actor,
    ): void;
}
