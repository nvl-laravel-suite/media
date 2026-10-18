<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Nvl\Media\Contracts\RenameMediaContract;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Support\Events\DomainEventDispatcher;

/**
 * Renames the display filename for a media record.
 *
 * @api
 */
final class RenameMediaAction implements RenameMediaContract
{
    public function __construct(
        private readonly MediaMutationLock $mutationLock,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Rename a media file's display filename.
     *
     * @param  Media|string  $media  Media instance or UUID
     * @param  string  $filename  New display filename
     * @return Media Fresh media model with translations and variations
     */
    public function execute(Media|string $media, string $filename): Media
    {
        $mediaId = $media instanceof Media ? $media->id : $media;
        $freshMedia = $this->mutationLock->execute($mediaId, function () use ($mediaId, $filename): Media {
            return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($mediaId, $filename): Media {
                $media = MediaModelRegistry::query()->lockForUpdate()->findOrFail($mediaId);
                $media->update(['filename' => $filename]);

                $this->domainEvents->dispatch(new MediaMutated($media->id, 'renamed'), $media->getConnection());

                return MediaModelRegistry::query()
                    ->with(['imageVariations', 'translations'])
                    ->findOrFail($media->id);
            });
        });

        return $freshMedia;
    }
}
