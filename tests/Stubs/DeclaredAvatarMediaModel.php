<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

use Nvl\Media\Conversions\ConversionDefinition;

/** Declares reproducible host conversions after canonical owner reload. */
final class DeclaredAvatarMediaModel extends TestMediaModel
{
    public function registerMediaSlots(): void
    {
        $this->addMediaSlot('avatar')->addConversion('thumb', static fn (ConversionDefinition $definition): ConversionDefinition => $definition->width(100)->height(100));
    }
}
