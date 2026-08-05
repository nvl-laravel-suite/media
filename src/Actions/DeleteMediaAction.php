<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Exceptions\MediaInUseException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Support\MediaAssociationSnapshot;

/**
 * Soft-deletes a media record and removes its objects after the root commit.
 */
final class DeleteMediaAction implements DeleteMediaContract
{
    /**
     * Create the media deletion action.
     */
    public function __construct(
        private readonly MediaFileEffectScheduler $fileEffects,
        private readonly MediaMutationLock $mutationLock,
    ) {}

    /**
     * Delete the media record while retaining a diagnostic tombstone.
     */
    public function execute(Media|string $media, bool $force = false): bool
    {
        $mediaId = $media instanceof Media ? $media->id : $media;

        return $this->mutationLock->execute(
            $mediaId,
            fn (): bool => $this->executeUnderAcquiredLock($mediaId, $force),
        );
    }

    /**
     * Execute deletion after the media mutation lock has been acquired.
     */
    private function executeUnderAcquiredLock(Media|string $media, bool $force = false): bool
    {
        $mediaId = $media instanceof Media ? $media->id : $media;

        return (function () use ($mediaId, $force): bool {
            $originalPath = '';
            $variationPaths = [];
            $affectedAssociations = [];
            $disk = '';

            $deleted = DB::transaction(function () use (
                $mediaId,
                $force,
                &$originalPath,
                &$variationPaths,
                &$affectedAssociations,
                &$disk,
            ): bool {
                $lockedMedia = Media::query()
                    ->with(['associations', 'imageVariations'])
                    ->lockForUpdate()
                    ->findOrFail($mediaId);

                if (! $force
                    && $lockedMedia->is_public
                    && config('media.prevent_deleting_reused_public_media', true)
                    && $lockedMedia->associations->count() > 1) {
                    throw MediaInUseException::publicAsset(
                        $lockedMedia->id,
                        $lockedMedia->associations->count(),
                    );
                }

                $disk = $lockedMedia->disk;
                $originalPath = $lockedMedia->buildPath();
                $variationPaths = $lockedMedia->imageVariations->map(
                    static fn (MediaImageVariation $variation): string => $variation->getPath(),
                )->all();
                $affectedAssociations = MediaAssociationSnapshot::fromAssociations(
                    $lockedMedia->associations,
                );

                return (bool) $lockedMedia->delete();
            });

            if (! $deleted) {
                return false;
            }

            if ((bool) config('media.delete_files_on_media_delete', true)) {
                $this->fileEffects->deleteAfterCommit(
                    $disk,
                    [$originalPath, ...$variationPaths],
                    'delete_media_objects',
                );
            }

            MediaMutated::dispatch($mediaId, 'deleted', $affectedAssociations);

            return true;
        })();
    }
}
