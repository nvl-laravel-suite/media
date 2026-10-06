<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;

/**
 * Signs a fixed-size checksummed part from persisted session state.
 *
 * @api
 */
interface SignMultipartPartContract
{
    /** Execute the sign multipart part workflow. */
    public function execute(
        SignMultipartPartData $part,
        MediaActorData $actor,
    ): SignedMultipartPartData;
}
