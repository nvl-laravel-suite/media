<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

use Illuminate\Auth\Authenticatable;

/**
 * Test owner with private and reusable single-file Media slots.
 */
final class OwnerSlotWorkflowModel extends TestMediaModel implements \Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable;

    public function registerMediaSlots(): void
    {
        $this->addMediaSlot('multi');
        $this->addMediaSlot(str_repeat('a', 51))->singleFile();
        $this->addMediaSlot('document')->oneToOne()->acceptsMimeTypes(['application/pdf'])->maxFileSize(2048);
        $this->addMediaSlot('library')->publicReusable()->singleFile()->acceptsMimeTypes(['application/pdf'])->maxFileSize(2048);
        $this->addMediaSlot('custom')->oneToOne()->acceptsFile(static fn (): bool => true);
        $this->addMediaSlot('converted')->oneToOne()->convertTo('webp')->maxSize(16);
    }
}
