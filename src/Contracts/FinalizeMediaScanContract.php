<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\MediaScanResultData;
use Nvl\Media\Models\Media;

/**
 * Finalizes an out-of-band scan using scanner-attested technical metadata.
 *
 * @api
 */
interface FinalizeMediaScanContract
{
    /** Execute the finalize media scan workflow. */
    public function execute(
        Media|string $media,
        MediaScanResultData $result,
    ): Media;
}
