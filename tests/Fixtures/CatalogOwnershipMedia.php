<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

/** Explicit host opt-in to central source assets and recipient-owned copies. */
final class CatalogOwnershipMedia extends OwnershipMedia
{
    public static function allowsCentralCatalog(): bool
    {
        return true;
    }
}
