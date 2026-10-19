<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Media\Compatibility\LegacyMediaCatalogImporter;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Data\MediaCatalogSnapshot;
use Nvl\Media\Data\StagedCatalogMedia;
use Nvl\Media\Models\Media;
use Nvl\Media\Ownership\MediaOwnerCatalog;

/** Routes copy workflows through native ownership or the explicitly temporary historical bridge. @internal */
final readonly class MediaCatalogImporter implements MediaCatalogImport
{
    /** Retain the independently guarded native and historical boundaries. */
    public function __construct(private Repository $configuration, private MediaOwnerCatalog $owned, private LegacyMediaCatalogImporter $legacy) {}

    /** Inspect the grant in the selected authority. */
    public function inspect(string $grantId): MediaCatalogSnapshot
    {
        return $this->configuration->get('nvl-ownership.enabled') === true ? $this->owned->inspect($grantId) : $this->legacy->inspect($grantId);
    }

    /** Stage a private copy after fresh source admission. */
    public function stage(MediaCatalogSnapshot $source): StagedCatalogMedia
    {
        return $this->configuration->get('nvl-ownership.enabled') === true ? $this->owned->stage($source) : $this->legacy->stage($source);
    }

    /** Persist only a staged copy belonging to the current authority and source revision. */
    public function persist(MediaCatalogSnapshot $source, StagedCatalogMedia $file, string $idempotencyKey): Media
    {
        return $this->configuration->get('nvl-ownership.enabled') === true ? $this->owned->persist($source, $file, $idempotencyKey) : $this->legacy->persist($source, $file, $idempotencyKey);
    }

    /** Discard an uncommitted stage through its guarded authority. */
    public function discard(StagedCatalogMedia $file): void
    {
        if ($this->configuration->get('nvl-ownership.enabled') === true) {
            $this->owned->discard($file);

            return;
        }
        $this->legacy->discard($file);
    }
}
