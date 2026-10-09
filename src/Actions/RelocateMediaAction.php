<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Nvl\Media\Contracts\RelocateMediaContract;
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
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Events\DomainEventDispatcher;
use Throwable;

/**
 * Relocates one media binary and its current variations to another disk.
 *
 * @api
 */
final readonly class RelocateMediaAction implements RelocateMediaContract
{
    private MediaPathResolver $paths;

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
        private DomainEventDispatcher $domainEvents,
        private ConnectionCommitCallbacks $eventCommits,
        ?MediaPathResolver $paths = null,
    ) {
        $this->paths = $paths ?? new MediaPathResolver;
    }

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
        $rewritePaths = MediaPathResolver::rootFolder($sourceDisk) !== MediaPathResolver::rootFolder($disk);
        $sourceOriginal = $media->buildPath();
        $targetOriginal = $rewritePaths
            ? $this->paths->mediaPathForFolder($media, $media->folder ?? '', $disk)
            : $sourceOriginal;
        $paths = [$sourceOriginal => $targetOriginal];
        $variationPaths = [];

        foreach ($media->imageVariations as $variation) {
            $targetPath = $rewritePaths
                ? $this->paths->variationPathForFolder($media, $variation, $media->folder ?? '', $disk)
                : $variation->getPath();
            $paths[$variation->getPath()] = $targetPath;
            $variationPaths[$variation->id] = $targetPath;
        }

        $staged = [];

        $writeCommitted = false;
        try {
            foreach ($paths as $sourcePath => $targetPath) {
                if (! $this->existence->existsFresh($sourceDisk, $sourcePath)) {
                    throw new MediaUploadException(
                        "Media relocation source is missing [{$sourceDisk}:{$sourcePath}].",
                    );
                }

                if ($this->existence->existsFresh($disk, $targetPath)) {
                    throw new MediaUploadException(
                        "Media relocation destination already exists [{$disk}:{$targetPath}].",
                    );
                }

                $staged[] = $targetPath;

                if (! $this->files->copy($sourceDisk, $sourcePath, $disk, $targetPath, $visibility)) {
                    throw new MediaUploadException(
                        "Media relocation copy failed [{$sourceDisk}:{$sourcePath}] to [{$disk}:{$targetPath}].",
                    );
                }

                $this->assertIntegrity($sourceDisk, $sourcePath, $disk, $targetPath);
            }

            $relocated = (new Media)->getConnection()->transaction(function () use (
                $mediaId,
                $disk,
                $visibility,
                $expectedRevision,
                $staged,
                $sourceDisk,
                $targetOriginal,
                $paths,
                $variationPaths,
                $rewritePaths,
                &$writeCommitted,
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
                    ...(config('nvl-tenancy.enabled') === true ? ['storage_path' => $targetOriginal] : []),
                ])->save();
                $locked->imageVariations()->update(['source_revision' => $nextRevision]);
                if ($rewritePaths) {
                    foreach ($variationPaths as $variationId => $targetPath) {
                        $locked->imageVariations()->whereKey($variationId)->update(['storage_path' => $targetPath]);
                    }
                }

                $this->eventCommits->afterCommit((new Media)->getConnection(), static function () use (&$writeCommitted): void {
                    $writeCommitted = true;
                });
                $this->fileEffects->deleteAfterCommit($sourceDisk, array_keys($paths), 'relocate_source');
                $this->domainEvents->dispatch(new MediaMutated($locked->id, 'relocated'), $locked->getConnection());

                return $locked->refresh()->load('imageVariations');
            });
        } catch (Throwable $exception) {
            if ($writeCommitted) {
                throw $exception;
            }

            $this->fileEffects->deleteNow($disk, $staged, 'relocate_pre_commit_failure');

            throw $exception;
        }

        return $relocated;
    }

    /**
     * Verify copied object size and digest before database state changes.
     *
     * @throws MediaUploadException
     */
    private function assertIntegrity(string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath): void
    {
        if ($this->disks->size($sourceDisk, $sourcePath) !== $this->disks->size($targetDisk, $targetPath)
            || ! hash_equals(
                $this->disks->checksum($sourceDisk, $sourcePath),
                $this->disks->checksum($targetDisk, $targetPath),
            )) {
            throw new MediaUploadException(
                "Media relocation integrity verification failed [{$targetDisk}:{$targetPath}].",
            );
        }
    }
}
