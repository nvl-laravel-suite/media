<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Illuminate\Database\Connection;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;

/** Checks package-specific natural identities in live source storage. @internal */
final readonly class MediaOwnershipConstraints
{
    /** @param list<string> $columns */
    public function assertUnique(Connection $connection, string $table, array $columns): void
    {
        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return;
            }
        }
        throw new OwnershipSchemaNotReady('The Media ownership identity ['.$table.':'.implode(',', $columns).'] is missing from live storage.');
    }
}
