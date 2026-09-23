<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Data\Display\MediaManagementData;
use Nvl\Media\Models\Media;

/** Builds the canonical privileged Media management payload. */
final class MediaManagementDataFactory
{
    /** @return array<string, mixed> */
    public function fromModel(Media $media): array
    {
        return MediaManagementData::fromModel($media)->toArray();
    }
}
