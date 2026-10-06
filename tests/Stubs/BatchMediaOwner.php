<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;

/** Canonical attribute-dependent slots for the batch reader fixture. */
class BatchMediaOwner extends TestMediaModel
{
    /** Define exact canonical slot names with varied reader ceilings. */
    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        if ($this->getAttribute('name') !== 'Restricted') {
            $media->slot('document')->singleFile();
        }
        $media->slot('library')->onlyKeepLatest(3);
        $media->slot('unbounded');
        $media->slot('0');
        for ($index = 0; $index < 20; $index++) {
            $media->slot('slot'.$index);
        }
    }
}
