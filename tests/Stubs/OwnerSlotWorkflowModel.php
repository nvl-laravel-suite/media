<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

/**
 * Test owner with private and reusable single-file Media slots.
 */
final class OwnerSlotWorkflowModel extends TestMediaModel
{
    public function registerMediaSlots(): void
    {
        $this->addMediaSlot('document')
            ->oneToOne()
            ->acceptsMimeTypes(['application/pdf'])
            ->maxFileSize(2_048);

        $this->addMediaSlot('library')
            ->publicReusable()
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf'])
            ->maxFileSize(2_048);

        $this->addMediaSlot('custom')
            ->oneToOne()
            ->acceptsFile(static fn (): bool => true);
    }
}
