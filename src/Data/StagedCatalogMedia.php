<?php

declare(strict_types=1);

namespace Nvl\Media\Data;

/** Exact importer-owned staged object tuple. */
final readonly class StagedCatalogMedia
{
    public function __construct(
        public string $operationId,
        public string $tenantId,
        public string $disk,
        public string $storagePath,
        public string $digest,
        public int $size,
    ) {}
}
