<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Media\Compatibility\LegacyMediaCatalogReader;
use Nvl\Media\Data\MediaCatalogSnapshot;
use Nvl\Media\Ownership\MediaOwnerCatalog;

/** Resolves the explicit native authority before entering the historical catalog bridge. @internal */
final readonly class MediaCatalogReader
{
    /** Retain the native reader and temporary compatibility boundary. */
    public function __construct(private Repository $configuration, private MediaOwnerCatalog $owned, private LegacyMediaCatalogReader $legacy) {}

    /** Inspect a grant through exactly one configured authority. */
    public function find(string $grantId): MediaCatalogSnapshot
    {
        return $this->configuration->get('nvl-ownership.enabled') === true ? $this->owned->inspect($grantId) : $this->legacy->find($grantId);
    }
}
