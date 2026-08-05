<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Nvl\Media\Services\MediaMutationLock;

/**
 * Deletes a set of media records through the single-record delete workflow.
 *
 * This bulk action intentionally delegates to DeleteMediaAction so file cleanup,
 * variation cleanup, and delete semantics remain identical to single deletes.
 */
final class BulkDeleteMediaAction
{
    public function __construct(
        private readonly DeleteMediaAction $deleteAction,
        private readonly MediaMutationLock $mutationLock,
    ) {}

    /**
     * Delete multiple media records by UUID.
     *
     * @param  array<int, string>  $ids  Media UUIDs
     * @return int Number of successfully deleted records
     */
    public function execute(array $ids): int
    {
        $ids = array_values(array_unique($ids));

        return $this->mutationLock->executeMany($ids, function () use ($ids): int {
            $count = 0;

            foreach ($ids as $id) {
                if ($this->deleteAction->execute($id)) {
                    $count++;
                }
            }

            return $count;
        });
    }
}
