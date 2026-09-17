<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\MediaCatalogSnapshot;
use Nvl\Media\Data\StagedCatalogMedia;
use Nvl\Media\Models\Media;

/** Public staged transaction port for composing Media copies into consumer graphs. */
interface MediaCatalogImport
{
    public function inspect(string $grantId): MediaCatalogSnapshot;

    public function stage(MediaCatalogSnapshot $source): StagedCatalogMedia;

    public function persist(MediaCatalogSnapshot $source, StagedCatalogMedia $file, string $idempotencyKey): Media;

    public function discard(StagedCatalogMedia $file): void;
}
