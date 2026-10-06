<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

/**
 * Deletes a set of media records through the single-record delete workflow.
 *
 * @api
 */
interface BulkDeleteMediaContract
{
    /**
     * Delete multiple media records by UUID.
     *
     * @param  array<int, string>  $ids  Media UUIDs
     * @return int Number of successfully deleted records
     */
    public function execute(array $ids): int;
}
