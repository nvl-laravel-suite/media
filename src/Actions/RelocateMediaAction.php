<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaMutationLock;
use Throwable;

/**
 * Relocates one media binary and its current variations to another disk.
 */
final readonly class RelocateMediaAction
{
    /**
     * Create the single-media relocation action.
     */
    public function __construct(
        private MediaDiskGateway $disks,
        private MediaDiskGuard $diskGuard,
        private MediaFileEffectScheduler $fileEffects,
        private MediaFileExistence $existence,
        private MediaFileOperator $files,
        private MediaMutationLock $mutationLock,
    ) {}

    /**
     * Copy, verify, and atomically swap one media record to a different disk.
     *
     * @throws MediaUploadException
     * @throws Throwable
     */
    public function execute(
        Media|string $media,
        string $disk,
        MediaVisibility $visibility,
        ?int $expectedRevision = null,
    ): Media {
        $mediaId = $media instanceof Media ? $media->id : $media;
        $this->diskGuard->assertAllowed($disk);
        $this->disks->ensureDefined($disk);

        return $this->mutationLock->execute(
            $mediaId,
            fn (): Media => $this->relocate($mediaId, $disk, $visibility, $expectedRevision),
        );
    }

    /**
     * Perform the copy-verify-swap workflow while the media lock is held.
     *
     * @throws MediaUploadException
     * @throws Throwable
     */
    private function relocate(
        string $mediaId,
        string $disk,
        MediaVisibility $visibility,
        ?int $expectedRevision,
    ): Media {
        $media = Media::query()->with('imageVariations')->findOrFail($mediaId);

        if ($expectedRevision !== null && $media->revision !== $expectedRevision) {
            throw new MediaUploadException(
                "Media [{$mediaId}] changed before relocation could begin.",
            );
        }

        if ($media->disk === $disk) {
            throw new MediaUploadException(
                "Media [{$mediaId}] is already stored on disk [{$disk}].",
            );
        }

        $sourceDisk = $media->disk;
        $paths = [$media->buildPath()];

        foreach ($media->imageVariations as $variation) {
            $paths[] = $variation->getPath();
        }

        $staged = [];

        try {
            foreach (array_values(array_unique($paths)) as $path) {
                if (! $this->existence->existsFresh($sourceDisk, $path)) {
                    throw new MediaUploadException(
                        "Media relocation source is missing [{$sourceDisk}:{$path}].",
                    );
                }

                if ($this->existence->existsFresh($disk, $path)) {
                    throw new MediaUploadException(
                        "Media relocation destination already exists [{$disk}:{$path}].",
                    );
                }

                $staged[] = $path;

                if (! $this->files->copy($sourceDisk, $path, $disk, $path, $visibility)) {
                    throw new MediaUploadException(
                        "Media relocation copy failed [{$sourceDisk}:{$path}] to [{$disk}:{$path}].",
                    );
                }

                $this->assertIntegrity($sourceDisk, $disk, $path);
            }

            $relocated = DB::transaction(function () use (
                $mediaId,
                $disk,
                $visibility,
                $expectedRevision,
                $staged,
            ): Media {
                $locked = Media::query()->with('imageVariations')->lockForUpdate()->findOrFail($mediaId);

                if ($expectedRevision !== null && $locked->revision !== $expectedRevision) {
                    throw new MediaUploadException(
                        "Media [{$mediaId}] changed while relocation was in progress.",
                    );
                }

                foreach ($staged as $path) {
                    $this->fileEffects->deleteAfterRollback(
                        $disk,
                        [$path],
                        'relocate_destination',
                    );
                }

                $nextRevision = $locked->revision + 1;
                $locked->forceFill([
                    'disk' => $disk,
                    'visibility' => $visibility,
                    'revision' => $nextRevision,
                ])->save();
                $locked->imageVariations()->update(['source_revision' => $nextRevision]);

                return $locked->refresh()->load('imageVariations');
            });
        } catch (Throwable $exception) {
            $this->fileEffects->deleteNow($disk, $staged, 'relocate_pre_commit_failure');

            throw $exception;
        }

        $this->fileEffects->deleteAfterCommit($sourceDisk, $staged, 'relocate_source');
        $this->fileEffects->afterCommit(
            static function () use ($mediaId): void {
                MediaMutated::dispatch($mediaId, 'relocated');
            },
        );

        return $relocated;
    }

    /**
     * Verify copied object size and digest before database state changes.
     *
     * @throws MediaUploadException
     */
    private function assertIntegrity(string $sourceDisk, string $targetDisk, string $path): void
    {
        if ($this->disks->size($sourceDisk, $path) !== $this->disks->size($targetDisk, $path)
            || ! hash_equals(
                $this->disks->checksum($sourceDisk, $path),
                $this->disks->checksum($targetDisk, $path),
            )) {
            throw new MediaUploadException(
                "Media relocation integrity verification failed [{$targetDisk}:{$path}].",
            );
        }
    }
}
