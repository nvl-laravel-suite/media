<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

/**
 * Test owner with a persisted replacement policy.
 */
final class RestrictedMediaModel extends TestMediaModel
{
    public function registerMediaSlots(): void
    {
        $this->addMediaSlot('documents')
            ->acceptsMimeTypes(['text/plain'])
            ->maxFileSize(1024);
    }
}
