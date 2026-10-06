<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Models\Media;

/**
 * Updates mutable media metadata and localized text fields atomically.
 *
 * @api
 */
interface UpdateMediaMetadataContract
{
    /**
     * Update metadata and translation fields for a media record.
     */
    public function execute(Media|string $media, UpdateMediaPayload $data): Media;
}
