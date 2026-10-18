<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\ReplaceMediaFileContract;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaReplacementStager;
use Nvl\Media\Services\MediaVariationDispatcher;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Events\DomainEventDispatcher;
use RuntimeException;
use Throwable;

/**
 * Replaces a media record's original file while preserving associations.
 *
 * @api
 */
final class ReplaceMediaFileAction implements ReplaceMediaFileContract
{
    /**
     * Create the replacement action.
     */
    public function __construct(
        private readonly MediaReplacementStager $stager,
        private readonly MediaFileEffectScheduler $fileEffects,
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaVariationDispatcher $variationDispatcher,
        private DomainEventDispatcher $domainEvents,
        private ConnectionCommitCallbacks $eventCommits,
    ) {}

    /**
     * Replace the original file and regenerate variations for the committed media state.
     *
     * @throws Throwable
     */
    public function execute(Media|string $media, UploadedFile $file): Media
    {
        $mediaId = $media instanceof Media ? $media->id : $media;

        return $this->mutationLock->execute($mediaId, function () use ($mediaId, $file): Media {
            $currentMedia = MediaModelRegistry::query()
                ->with('imageVariations')
                ->findOrFail($mediaId);
            $staged = $this->stager->stage($currentMedia, $file);
            $sourceDisk = $currentMedia->disk;
            $sourcePath = $currentMedia->buildPath();
            $sourceRevision = $currentMedia->revision;
            $sourceVariationPaths = $currentMedia->imageVariations->map(
                static fn (MediaImageVariation $variation): string => $variation->getPath(),
            )->all();

            $writeCommitted = false;
            try {
                (MediaModelRegistry::newInstance())->getConnection()->transaction(function () use (
                    $mediaId,
                    $sourceDisk,
                    $sourcePath,
                    $sourceRevision,
                    $sourceVariationPaths,
                    $staged,
                    &$writeCommitted,
                ): void {
                    $this->fileEffects->deleteAfterRollback(
                        $sourceDisk,
                        [$staged->path],
                        'replace_new_object',
                    );

                    $lockedMedia = MediaModelRegistry::query()
                        ->with('imageVariations')
                        ->lockForUpdate()
                        ->findOrFail($mediaId);

                    if ($lockedMedia->disk !== $sourceDisk
                        || $lockedMedia->buildPath() !== $sourcePath
                        || $lockedMedia->revision !== $sourceRevision) {
                        throw new RuntimeException(
                            "Media [{$mediaId}] changed while its replacement was being staged.",
                        );
                    }

                    $lockedMedia->imageVariations()->update([
                        'status' => MediaLifecycleStatus::ProcessingVariations->value,
                    ]);
                    $lockedMedia->update([
                        'filename' => $staged->validatedFile->displayFilename,
                        'hash' => $staged->hash,
                        'extension' => $staged->validatedFile->extension,
                        'mime_type' => $staged->validatedFile->mimeType,
                        'size' => $staged->validatedFile->size,
                        'type' => $staged->validatedFile->type,
                        'digest' => $staged->validatedFile->digest,
                        'revision' => $lockedMedia->revision + 1,
                        'status' => MediaLifecycleStatus::Available,
                        'available_at' => now(),
                        'failure_code' => null,
                        'failure_context' => null,
                    ]);
                    $this->eventCommits->afterCommit((MediaModelRegistry::newInstance())->getConnection(), static function () use (&$writeCommitted): void {
                        $writeCommitted = true;
                    });
                    $this->fileEffects->deleteAfterCommit(
                        $sourceDisk,
                        [$sourcePath, ...$sourceVariationPaths],
                        'replace_superseded_objects',
                    );
                    $this->variationDispatcher->dispatchForCurrentState($lockedMedia->refresh()->load(['associations.associable', 'imageVariations', 'translations']));
                    $this->domainEvents->dispatch(new MediaMutated($lockedMedia->id, 'file_replaced'), $lockedMedia->getConnection());
                });
            } catch (Throwable $exception) {
                if ($writeCommitted) {
                    throw $exception;
                }

                $this->fileEffects->deleteNow(
                    $sourceDisk,
                    [$staged->path],
                    'replace_pre_commit_failure',
                );

                throw $exception;
            }

            $freshMedia = MediaModelRegistry::query()
                ->with(['associations.associable', 'imageVariations', 'translations'])
                ->findOrFail($mediaId);

            return $freshMedia;
        });
    }
}
