<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaPathResolver;
use RuntimeException;
use Throwable;

/**
 * Moves media objects through a copy-swap-delete workflow that survives outer rollbacks.
 */
final class BulkMoveMediaAction
{
    /**
     * Create the bulk move action.
     */
    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaFileEffectScheduler $fileEffects,
        private readonly MediaFileExistence $existence,
        private readonly MediaFileOperator $files,
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaPathResolver $pathResolver,
    ) {}

    /**
     * Move multiple media records to a normalized target folder.
     *
     * @param  list<string>  $ids
     *
     * @throws Throwable
     */
    public function execute(array $ids, string $folder): int
    {
        $sanitizedFolder = $this->pathResolver->normalizeFolder($folder);
        $mediaIds = array_values(
            Media::query()
                ->whereIn('id', array_values(array_unique($ids)))
                ->pluck('id')
                ->filter(static fn (mixed $id): bool => is_string($id))
                ->all(),
        );

        if ($mediaIds === []) {
            return 0;
        }

        return $this->mutationLock->executeMany($mediaIds, function () use (
            $mediaIds,
            $sanitizedFolder,
        ): int {
            $mediaItems = Media::query()
                ->with('imageVariations')
                ->whereIn('id', $mediaIds)
                ->get();
            $copies = [];

            foreach ($mediaItems as $media) {
                $oldPath = $media->buildPath();
                $newPath = $this->pathResolver->mediaPathForFolder($media, $sanitizedFolder);

                if ($oldPath !== $newPath) {
                    $copies[] = [
                        'disk' => $media->disk,
                        'old' => $oldPath,
                        'new' => $newPath,
                        'variation_id' => null,
                    ];
                }

                foreach ($media->imageVariations as $variation) {
                    $oldVariationPath = $variation->getPath();
                    $newVariationPath = $this->pathResolver->variationPathForFolder(
                        $media,
                        $variation,
                        $sanitizedFolder,
                    );

                    if ($oldVariationPath !== $newVariationPath) {
                        $copies[] = [
                            'disk' => $media->disk,
                            'old' => $oldVariationPath,
                            'new' => $newVariationPath,
                            'variation_id' => $variation->id,
                        ];
                    }
                }
            }

            $completedCopies = [];

            try {
                foreach ($copies as $copy) {
                    if ($this->existence->exists($copy['disk'], $copy['new'])) {
                        throw new RuntimeException(
                            "Media bulk move destination already exists [{$copy['new']}].",
                        );
                    }

                    if (! $this->files->copy(
                        $copy['disk'],
                        $copy['old'],
                        $copy['disk'],
                        $copy['new'],
                    )) {
                        throw new RuntimeException(
                            "Media bulk move copy failed [{$copy['old']}] to [{$copy['new']}].",
                        );
                    }

                    $completedCopies[] = $copy;

                    if ($this->disks->size($copy['disk'], $copy['old'])
                        !== $this->disks->size($copy['disk'], $copy['new'])
                        || ! hash_equals(
                            $this->disks->checksum($copy['disk'], $copy['old']),
                            $this->disks->checksum($copy['disk'], $copy['new']),
                        )) {
                        throw new RuntimeException(
                            "Media bulk move integrity verification failed [{$copy['new']}].",
                        );
                    }
                }

                DB::transaction(function () use (
                    $mediaIds,
                    $sanitizedFolder,
                    $completedCopies,
                ): void {
                    foreach ($completedCopies as $copy) {
                        $this->fileEffects->deleteAfterRollback(
                            $copy['disk'],
                            [$copy['new']],
                            'bulk_move_destination',
                        );

                        if (is_string($copy['variation_id'])) {
                            MediaImageVariation::query()
                                ->whereKey($copy['variation_id'])
                                ->update(['storage_path' => $copy['new']]);
                        }
                    }

                    Media::query()
                        ->whereIn('id', $mediaIds)
                        ->lockForUpdate()
                        ->get()
                        ->each(
                            static fn (Media $media): bool => $media
                                ->forceFill(['folder' => $sanitizedFolder])
                                ->save(),
                        );
                });
            } catch (Throwable $exception) {
                foreach ($completedCopies as $copy) {
                    $this->fileEffects->deleteNow(
                        $copy['disk'],
                        [$copy['new']],
                        'bulk_move_pre_commit_failure',
                    );
                }

                throw $exception;
            }

            foreach ($completedCopies as $copy) {
                $this->fileEffects->deleteAfterCommit(
                    $copy['disk'],
                    [$copy['old']],
                    'bulk_move_source',
                );
            }

            foreach ($mediaIds as $mediaId) {
                MediaMutated::dispatch($mediaId, 'moved');
            }

            return count($mediaIds);
        });
    }
}
