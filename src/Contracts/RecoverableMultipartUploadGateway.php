<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\Multipart\CompletedMultipartObjectData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;

/**
 * Multipart gateway capable of inspecting an object after an interrupted completion.
 */
interface RecoverableMultipartUploadGateway extends MultipartUploadGateway
{
    public function inspect(
        MultipartUploadSessionData $session,
    ): ?CompletedMultipartObjectData;
}
