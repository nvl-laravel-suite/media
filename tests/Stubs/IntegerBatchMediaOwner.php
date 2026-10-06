<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

/** Native integer-key owner sharing the NVL capability. */
final class IntegerBatchMediaOwner extends BatchMediaOwner
{
    protected $table = 'batch_integer_media_owners';

    /** Preserve Laravel's native integer identity strategy.
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
