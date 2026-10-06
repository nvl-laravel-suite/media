<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Throwable;

/**
 * Moves media objects through a copy-swap-delete workflow that survives outer rollbacks.
 *
 * @api
 */
interface BulkMoveMediaContract
{
    /**
     * Move multiple media records to a normalized target folder.
     *
     * @param  list<string>  $ids
     *
     * @throws Throwable
     */
    public function execute(array $ids, string $folder): int;
}
