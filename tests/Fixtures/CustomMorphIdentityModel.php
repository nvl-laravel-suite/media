<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

/** Owner identity fixture that customises its morph class. */
final class CustomMorphIdentityModel extends CountedIdentityModel
{
    public function getMorphClass(): string
    {
        return 'custom-identity';
    }
}
