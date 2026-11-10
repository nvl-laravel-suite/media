<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Nvl\Media\Models\Media;
use Nvl\Media\Tests\Fixtures\Data\Display\MediaManagementData;

/** Builds the canonical privileged Media management payload. */
final class MediaManagementDataFactory
{
    /** @return array<string, mixed> */
    public function fromModel(Media $media): array
    {
        return (new MediaManagementData($media))->resolve();
    }
}
