<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Models\Media;

/**
 * Adds tags to a set of media records in one transaction.
 *
 * @api
 */
interface BulkTagMediaContract
{
    /**
     * Add tags to multiple media records atomically.
     *
     * @param  array<int, string>  $ids  Media UUIDs
     * @param  array<int, string>  $tags  Tags to add
     * @return int Number of tagged records
     */
    public function execute(array $ids, array $tags): int;
}
