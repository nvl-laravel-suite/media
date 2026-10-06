<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

/** Owner whose declared connection cannot be erased by a caller. */
final class DeclaredConnectionBatchMediaOwner extends BatchMediaOwner
{
    protected $connection = 'batch_declared';
}
