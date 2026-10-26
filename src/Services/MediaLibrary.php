<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Exceptions\MediaInUseException;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Ownership\MediaOwnership;
use Nvl\Media\Support\MediaActors;
use Nvl\Media\Support\MediaAssociationSnapshot;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Nvl\Translatable\Services\RelatedTranslationStore;
use RuntimeException;
use Throwable;

/**
 * Complete native MediaLibrary operations, including persistence and file effects.
 *
 * @api
 */
final class MediaLibrary
{
    public function __construct(
        private readonly MediaAccessService $access,
        private readonly MediaMutationLock $mutationLock,
        private readonly DomainEventDispatcher $domainEvents,
        private readonly RelatedTranslationStore $translations,
        private readonly MediaDiskGateway $disks,
        private readonly MediaDiskGuard $diskGuard,
        private readonly MediaFileEffectScheduler $fileEffects,
        private readonly MediaFileExistence $existence,
        private readonly MediaFileOperator $files,
        private readonly ConnectionCommitCallbacks $eventCommits,
        private readonly MediaPathResolver $paths,
        private readonly MediaReader $queries,
        private readonly MediaPathResolver $pathResolver,
    ) {}

    /**
     * Rename a media file's display filename.
     *
     * @param  Media|string  $media  Media instance or UUID
     * @param  string  $filename  New display filename
     * @return Media Fresh media model with translations and variations
     */
    public function rename(Authenticatable|SystemActor $actor, Media|string $media, string $filename): Media
    {
        if (trim($filename) === '' || strlen($filename) > 255 || str_contains($filename, '/') || str_contains($filename, chr(92))) {
            throw new \InvalidArgumentException('Media filenames must be bounded display names without path separators.');
        }
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Mutate, MediaModelRegistry::query()->findOrFail($media instanceof Media ? $media->id : $media));
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

    /**
     * Create the media metadata action.
     */

    /**
     * Update metadata and translation fields for a media record.
     */
    /** Mutate only supplied display and localization attributes; omission preserves a value.
     * @param  array{isPublic?: bool, tags?: list<string>|null, metadata?: array<string, mixed>|null, translations?: array<string, array{title?: string|null, alt?: string|null, caption?: string|null, description?: string|null}>|null, translation_mode?: TranslationSyncMode}  $data
     */
    public function updateMetadata(Authenticatable|SystemActor $actor, Media|string $media, array $data): Media
    {
        $this->validateMetadata($data);

        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Mutate, MediaModelRegistry::query()->findOrFail($media instanceof Media ? $media->id : $media));
        $mediaId = $media instanceof Media ? $media->id : $media;
        $freshMedia = $this->mutationLock->execute($mediaId, function () use ($mediaId, $data): Media {
            return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($mediaId, $data): Media {
                $media = MediaModelRegistry::query()->lockForUpdate()->findOrFail($mediaId);
                $mediaFields = [];

                if (array_key_exists('tags', $data)) {
                    $mediaFields['tags'] = $data['tags'];
                }

                if (array_key_exists('metadata', $data)) {
                    $mediaFields['metadata'] = $data['metadata'];
                }

                if (array_key_exists('isPublic', $data)) {
                    if (! $data['isPublic'] && $media->is_public && $media->associations()->count() > 1) {
                        throw MediaInUseException::privateVisibility($media->id);
                    }

                    $mediaFields['is_public'] = $data['isPublic'];
                }

                if ($mediaFields !== []) {
                    $media->update($mediaFields);
                }

                $translationRows = [];

                if (array_key_exists('translations', $data) && is_array($data['translations'])) {
                    foreach ($data['translations'] as $locale => $attributes) {
                        $translationRows[$locale] = $attributes;
                    }
                }

                if ($translationRows !== [] || ($data['translation_mode'] ?? TranslationSyncMode::Patch)->value === 'replace') {
                    $definition = $media->translationDefinition();
                    $locales = [];
                    foreach ($translationRows as $locale => $attributes) {
                        $normalized = $definition->assertLocale($locale);
                        if (isset($locales[$normalized])) {
                            throw new \InvalidArgumentException('Duplicate normalized Media translation locale.');
                        }
                        $locales[$normalized] = true;
                        $this->translations->upsert($media, $definition, $normalized, $attributes);
                    }
                    if (($data['translation_mode'] ?? TranslationSyncMode::Patch) === TranslationSyncMode::Replace) {
                        $this->translations->deleteExcept($media, $definition, array_keys($locales));
                    }
                }

                $this->domainEvents->dispatch(new MediaMutated($media->id, 'metadata_updated'), $media->getConnection());

                return MediaModelRegistry::query()
                    ->with(['imageVariations', 'translations'])
                    ->findOrFail($media->id);
            });
        });

        return $freshMedia;
    }

    /**
     * Create the single-media relocation action.
     */

    /**
     * Copy, verify, and atomically swap one media record to a different disk.
     *
     * @throws MediaUploadException
     * @throws Throwable
     */
    public function relocate(Authenticatable|SystemActor $actor,
        Media|string $media,
        string $disk,
        MediaVisibility $visibility,
        ?int $expectedRevision = null,
    ): Media {
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Mutate, MediaModelRegistry::query()->findOrFail($media instanceof Media ? $media->id : $media));
        $mediaId = $media instanceof Media ? $media->id : $media;
        $this->diskGuard->assertAllowed($disk);
        $this->disks->ensureDefined($disk);

        return $this->mutationLock->execute(
            $mediaId,
            fn (): Media => $this->relocateRelocate($actor, $mediaId, $disk, $visibility, $expectedRevision),
        );
    }

    /**
     * Perform the copy-verify-swap workflow while the media lock is held.
     *
     * @throws MediaUploadException
     * @throws Throwable
     */
    private function relocateRelocate(Authenticatable|SystemActor $actor,
        string $mediaId,
        string $disk,
        MediaVisibility $visibility,
        ?int $expectedRevision,
    ): Media {
        $media = MediaModelRegistry::query()->with('imageVariations')->findOrFail($mediaId);

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

                $this->relocateAssertIntegrity($actor, $sourceDisk, $sourcePath, $disk, $targetPath);
            }

            $relocated = (MediaModelRegistry::newInstance())->getConnection()->transaction(function () use (
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
                $locked = MediaModelRegistry::query()->with('imageVariations')->lockForUpdate()->findOrFail($mediaId);

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
                    ...(MediaOwnership::active() ? ['storage_path' => $targetOriginal] : []),
                ])->save();
                $locked->imageVariations()->update(['source_revision' => $nextRevision]);
                if ($rewritePaths) {
                    foreach ($variationPaths as $variationId => $targetPath) {
                        $locked->imageVariations()->whereKey($variationId)->update(['storage_path' => $targetPath]);
                    }
                }

                $this->eventCommits->afterCommit((MediaModelRegistry::newInstance())->getConnection(), static function () use (&$writeCommitted): void {
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
    private function relocateAssertIntegrity(Authenticatable|SystemActor $actor, string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath): void
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

    /**
     * Create the media deletion action.
     */

    /**
     * Delete the media record while retaining a diagnostic tombstone.
     */
    public function delete(Authenticatable|SystemActor $actor, Media|string $media, bool $force = false, ?Model $owner = null): bool
    {
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Delete, MediaModelRegistry::query()->findOrFail($media instanceof Media ? $media->id : $media), $owner);
        $mediaId = $media instanceof Media ? $media->id : $media;

        return $this->mutationLock->execute(
            $mediaId,
            fn (): bool => $this->deleteExecuteUnderAcquiredLock($actor, $mediaId, $force),
        );
    }

    /**
     * Execute deletion after the media mutation lock has been acquired.
     */
    private function deleteExecuteUnderAcquiredLock(Authenticatable|SystemActor $actor, Media|string $media, bool $force = false): bool
    {
        $mediaId = $media instanceof Media ? $media->id : $media;

        return (function () use ($mediaId, $force): bool {
            $originalPath = '';
            $variationPaths = [];
            $affectedAssociations = [];
            $disk = '';

            $deleted = (MediaModelRegistry::newInstance())->getConnection()->transaction(function () use (
                $mediaId,
                $force,
                &$originalPath,
                &$variationPaths,
                &$affectedAssociations,
                &$disk,
            ): bool {
                $lockedMedia = MediaModelRegistry::query()
                    ->with(['associations', 'imageVariations'])
                    ->lockForUpdate()
                    ->findOrFail($mediaId);

                if (! $force
                    && $lockedMedia->is_public
                    && config('nvl-media.prevent_deleting_reused_public_media', true)
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

                $deleted = (bool) $lockedMedia->delete();
                if ($deleted) {
                    if ((bool) config('nvl-media.delete_files_on_media_delete', true)) {
                        $this->fileEffects->deleteAfterCommit(
                            $disk,
                            [$originalPath, ...$variationPaths],
                            'delete_media_objects',
                        );
                    }
                    $this->domainEvents->dispatch(new MediaMutated($mediaId, 'deleted', $affectedAssociations), $lockedMedia->getConnection());
                }

                return $deleted;
            });

            if (! $deleted) {
                return false;
            }

            return true;
        })();
    }

    /**
     * Delete multiple media records by UUID.
     *
     * @param  array<int, string>  $ids  Media UUIDs
     * @return int Number of successfully deleted records
     */
    public function bulkDelete(Authenticatable|SystemActor $actor, array $ids): int
    {
        $actor = MediaActors::assert($actor);
        foreach ($this->queries->findMany($actor, $ids) as $item) {
            $this->access->authorize($actor, MediaAbility::Delete, $item);
        }
        $ids = array_values(array_unique($ids));
        $this->queries->findMany($actor, $ids);

        return $this->mutationLock->executeMany($ids, function () use ($actor, $ids): int {
            $count = 0;

            foreach ($ids as $id) {
                if ($this->delete($actor, $id)) {
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * Create the bulk move action.
     */

    /**
     * Move multiple media records to a normalized target folder.
     *
     * @param  list<string>  $ids
     *
     * @throws Throwable
     */
    public function move(Authenticatable|SystemActor $actor, array $ids, string $folder): int
    {
        $actor = MediaActors::assert($actor);
        foreach ($this->queries->findMany($actor, $ids) as $item) {
            $this->access->authorize($actor, MediaAbility::Mutate, $item);
        }
        $sanitizedFolder = $this->pathResolver->normalizeFolder($folder);
        $mediaIds = array_values(
            $this->queries->findMany($actor, $ids)
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
            $mediaItems = MediaModelRegistry::query()
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

            $writeCommitted = false;
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

                (MediaModelRegistry::newInstance())->getConnection()->transaction(function () use (
                    $mediaIds,
                    $sanitizedFolder,
                    $completedCopies,
                    &$writeCommitted,
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

                    MediaModelRegistry::query()
                        ->whereIn('id', $mediaIds)
                        ->lockForUpdate()
                        ->get()
                        ->each(
                            static fn (Media $media): bool => $media
                                ->forceFill(['folder' => $sanitizedFolder])
                                ->save(),
                        );
                    $this->eventCommits->afterCommit((MediaModelRegistry::newInstance())->getConnection(), static function () use (&$writeCommitted): void {
                        $writeCommitted = true;
                    });
                    foreach ($mediaIds as $mediaId) {
                        $this->domainEvents->dispatch(new MediaMutated($mediaId, 'moved'), (MediaModelRegistry::newInstance())->getConnection());
                    }
                });
            } catch (Throwable $exception) {
                if (! $writeCommitted) {
                    foreach ($completedCopies as $copy) {
                        $this->fileEffects->deleteNow($copy['disk'], [$copy['new']], 'bulk_move_pre_commit_failure');
                    }
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

            return count($mediaIds);
        });
    }

    /**
     * Add tags to multiple media records atomically.
     *
     * @param  array<int, string>  $ids  Media UUIDs
     * @param  array<int, string>  $tags  Tags to add
     * @return int Number of tagged records
     */
    public function bulkTag(Authenticatable|SystemActor $actor, array $ids, array $tags): int
    {
        $actor = MediaActors::assert($actor);
        foreach ($this->queries->findMany($actor, $ids) as $item) {
            $this->access->authorize($actor, MediaAbility::Mutate, $item);
        }
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return 0;
        }
        $this->queries->findMany($actor, $ids);

        return $this->mutationLock->executeMany($ids, function () use ($ids, $tags): int {
            return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($ids, $tags): int {
                $mediaItems = MediaModelRegistry::query()
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $count = 0;

                foreach ($mediaItems as $media) {
                    /** @var Media $media */
                    $existing = $media->tags ?? [];
                    $merged = array_values(array_unique(array_merge($existing, $tags)));
                    $media->update(['tags' => $merged]);
                    $count++;
                }

                return $count;
            });
        });
    }

    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function tag(Authenticatable|SystemActor $actor,
        Media|string $media,
        array $add = [],
        array $remove = [],
    ): Media {
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Mutate, MediaModelRegistry::query()->findOrFail($media instanceof Media ? $media->id : $media));
        $mediaId = $media instanceof Media ? $media->id : $media;
        $add = $this->tagNormalize($actor, $add);
        $remove = $this->tagNormalize($actor, $remove);

        return $this->mutationLock->execute(
            $mediaId,
            function () use ($mediaId, $add, $remove): Media {
                return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($mediaId, $add, $remove): Media {
                    $locked = MediaModelRegistry::query()->lockForUpdate()->findOrFail($mediaId);
                    $tags = array_values(array_diff($locked->tags ?? [], $remove));
                    $locked->tags = array_values(array_unique([...$tags, ...$add]));
                    $locked->save();

                    return $locked;
                });
            },
        );
    }

    /** Validate native display mutations before actor resolution or persistence.
     * @param  array<string, mixed>  $data
     */
    private function validateMetadata(array $data): void
    {
        $unknown = array_diff(array_keys($data), ['tags', 'metadata', 'isPublic', 'translations', 'translation_mode']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown or protected Media metadata fields: '.implode(', ', $unknown));
        }
        if (array_key_exists('isPublic', $data) && ! is_bool($data['isPublic'])) {
            throw new \InvalidArgumentException('Media isPublic must be a native boolean.');
        }
        if (isset($data['tags']) && (! is_array($data['tags']) || ! array_is_list($data['tags']))) {
            throw new \InvalidArgumentException('Media tags must be a native list.');
        }
        Validator::make($data, [
            'tags' => ['nullable', 'array', 'list'], 'tags.*' => ['string', 'max:50'],
            'metadata' => ['nullable', 'array'], 'isPublic' => ['sometimes', 'boolean'],
            'translations' => ['nullable', 'array', new SupportedLocaleMapRule],
            'translations.*' => ['array:title,alt,caption,description'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.alt' => ['nullable', 'string', 'max:255'],
            'translations.*.caption' => ['nullable', 'string'],
            'translations.*.description' => ['nullable', 'string'],
        ])->validate();
        if (array_key_exists('translation_mode', $data) && ! $data['translation_mode'] instanceof TranslationSyncMode) {
            throw new \InvalidArgumentException('Media translation_mode must be a TranslationSyncMode.');
        }

        if (isset($data['metadata']) && is_array($data['metadata'])) {
            foreach (array_keys($data['metadata']) as $key) {
                if (! is_string($key)) {
                    throw new \InvalidArgumentException('Media metadata must use string keys.');
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $tags
     * @return list<string>
     */
    private function tagNormalize(Authenticatable|SystemActor $actor, array $tags): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $tag): string => trim($tag), $tags),
            static fn (string $tag): bool => $tag !== '',
        )));
    }
}
