<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

/** Owner with an explicit numeric native string morph identity. */
final class NumericBatchMediaOwner extends BatchMediaOwner
{
    /** Retain numeric native identity without PHP morph-map key casting. */
    public function getMorphClass(): string
    {
        return '7';
    }
}
