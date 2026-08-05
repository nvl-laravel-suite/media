<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaMutationLock;

/**
 * Renames the display filename for a media record.
 */
final class RenameMediaAction
{
    public function __construct(
        private readonly MediaMutationLock $mutationLock,
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
            return DB::transaction(function () use ($mediaId, $filename): Media {
                $media = Media::query()->lockForUpdate()->findOrFail($mediaId);
                $media->update(['filename' => $filename]);

                return Media::query()
                    ->with(['imageVariations', 'translations'])
                    ->findOrFail($media->id);
            });
        });

        MediaMutated::dispatch($freshMedia->id, 'renamed');

        return $freshMedia;
    }
}
