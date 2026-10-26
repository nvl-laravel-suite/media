<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Events\MediaUploaded;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Ownership\MediaOwnership;
use Nvl\Media\Ownership\MediaOwnershipParentResolver;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaActors;
use Nvl\Media\Support\MediaHashGenerator;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Logging\PackageLogger;
use RuntimeException;
use Throwable;

/**
 * Complete native MediaUploader operations, including persistence and file effects.
 *
 * @api
 */
final class MediaUploader
{
    public function __construct(
        private readonly MediaAccessService $access,
        private readonly MediaDiskGateway $disks,
        private readonly MediaDiskGuard $diskGuard,
        private readonly MediaFileEffectScheduler $fileEffects,
        private readonly MediaFileExistence $existence,
        private readonly MediaFileOperator $files,
        private readonly MediaIngestionPipeline $ingestion,
        private readonly MediaPathResolver $pathResolver,
        private readonly MediaVariationDispatcher $variationDispatcher,
        private readonly MediaDeduplicationLock $deduplicationLock,
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaVariationDefinitionNormalizer $definitionNormalizer,
        private readonly MediaOwnershipParentResolver $owners,
        private readonly DomainEventDispatcher $domainEvents,
        private readonly ConnectionCommitCallbacks $eventCommits,
        private readonly PackageLogger $packageLogger,
        private readonly MediaReplacementStager $stager,
    ) {}

    /**
     * Upload a file to the given disk and persist the Media record.
     *
     * @param  array<array-key, mixed>  $tags  Flat list of classification tags
     * @param  array<string, mixed>  $metadata  Arbitrary key-value pairs stored with the media
     * @param  string|null  $folderOverride  Override the resolved storage folder
     * @param  bool|null  $allowDuplicates  Force duplicate row creation and duplicate-safe filenames
     * @param  bool|null  $deduplicateExisting  Reuse an existing media row when one already exists for the same digest
     * @param  bool  $skipAutoVariations  Skip preset/output conversion generation for this upload
     * @param  array<string, ConversionDefinition|array<string, mixed>>  $variationDefinitions
     * @return Media The freshly created Media model
     *
     * @throws MediaUploadException When the file cannot be stored on disk
     */
    public function upload(Authenticatable|SystemActor $actor,
        UploadedFile $file,
        string $disk,
        Model $model,
        MediaSlot $slot,
        string $fileName,
        bool $isPublic = false,
        array $tags = [],
        array $metadata = [],
        ?string $folderOverride = null,
        ?bool $allowDuplicates = null,
        ?bool $deduplicateExisting = null,
        bool $skipAutoVariations = false,
        array $variationDefinitions = [],
    ): Media {
        $actor = MediaActors::assert($actor);
        if (MediaOwnership::active()) {
            $model = $this->owners->resolve($model);
        }
        $this->access->authorize($actor, MediaAbility::Upload, owner: $model);
        $this->diskGuard->assertAllowed($disk);
        $this->disks->ensureDefined($disk);
        $validatedFile = $this->ingestion->inspect($file, [$slot], $fileName);
        $normalizedVariationDefinitions = $this->definitionNormalizer->normalize($variationDefinitions);
        $uploaderId = MediaActors::id($actor);
        $uploaderType = MediaActors::type($actor);
        if (! array_is_list($tags) || array_filter($tags, static fn (mixed $tag): bool => ! is_string($tag) || strlen($tag) > 50) !== []) {
            throw new \InvalidArgumentException('Media tags must be a list of bounded strings.');
        }

        $folder = $folderOverride !== null
            ? $this->pathResolver->normalizeFolder($folderOverride)
            : $this->pathResolver->resolve($model, $slot);

        $duplicates_allowed = (bool) ($allowDuplicates ?? config('nvl-media.allow_duplicates', false));
        $should_deduplicate = $deduplicateExisting ?? ! $duplicates_allowed;

        if (! $isPublic && $uploaderId === null && ! config('nvl-media.deduplication.allow_anonymous_private', false)) {
            $should_deduplicate = false;
        }

        if ($should_deduplicate) {
            $existing = $this->uploadFindDeduplicateCandidate($actor,
                $validatedFile['digest'],
                $disk,
                $isPublic,
                $uploaderId,
                $uploaderType,
            );

            if ($existing !== null) {
                return $this->uploadReuseDeduplicatedMedia($actor,
                    $existing,
                    $normalizedVariationDefinitions,
                    $skipAutoVariations,
                );
            }

            return $this->deduplicationLock->execute(
                $validatedFile['digest'],
                $disk,
                $isPublic,
                $uploaderId,
                $uploaderType,
                function () use ($actor, $file, $disk, $isPublic, $tags, $metadata, $folder, $duplicates_allowed, $validatedFile, $uploaderId, $uploaderType, $skipAutoVariations, $normalizedVariationDefinitions): Media {
                    $existing = $this->uploadFindDeduplicateCandidate($actor,
                        $validatedFile['digest'],
                        $disk,
                        $isPublic,
                        $uploaderId,
                        $uploaderType,
                    );

                    if ($existing !== null) {
                        return $this->uploadReuseDeduplicatedMedia($actor,
                            $existing,
                            $normalizedVariationDefinitions,
                            $skipAutoVariations,
                        );
                    }

                    return $this->uploadStoreUploadedMedia($actor,
                        file: $file,
                        disk: $disk,
                        isPublic: $isPublic,
                        tags: $tags,
                        metadata: $metadata,
                        folder: $folder,
                        duplicatesAllowed: $duplicates_allowed,
                        validatedFile: $validatedFile,
                        uploaderId: $uploaderId,
                        uploaderType: $uploaderType,
                        skipAutoVariations: $skipAutoVariations,
                        variationDefinitions: $normalizedVariationDefinitions,
                    );
                },
            );
        }

        $media = $this->uploadStoreUploadedMedia($actor,
            file: $file,
            disk: $disk,
            isPublic: $isPublic,
            tags: $tags,
            metadata: $metadata,
            folder: $folder,
            duplicatesAllowed: $duplicates_allowed,
            validatedFile: $validatedFile,
            uploaderId: $uploaderId,
            uploaderType: $uploaderType,
            skipAutoVariations: $skipAutoVariations,
            variationDefinitions: $normalizedVariationDefinitions,
        );

        return $media;
    }

    /**
     * Store a validated upload on disk and persist a MediaModelRegistry::newInstance() row.
     *
     * @param  array<array-key, mixed>  $tags
     * @param  array<string, mixed>  $metadata
     * @param  array<string, array<string, mixed>>  $variationDefinitions
     * @param  array{displayFilename: string, extension: string, mimeType: string, size: int, digest: string, type: MediaType, realPath: string}  $validatedFile
     *
     * @throws MediaUploadException When the file cannot be stored on disk
     */
    private function uploadStoreUploadedMedia(Authenticatable|SystemActor $actor,
        UploadedFile $file,
        string $disk,
        bool $isPublic,
        array $tags,
        array $metadata,
        string $folder,
        bool $duplicatesAllowed,
        array $validatedFile,
        ?string $uploaderId,
        ?string $uploaderType,
        bool $skipAutoVariations,
        array $variationDefinitions,
    ): Media {
        $fileName = $validatedFile['displayFilename'];
        $hash = MediaHashGenerator::generateForExtension($validatedFile['extension']);

        if ($duplicatesAllowed) {
            $fileName = $this->uploadGenerateDuplicateFileName($actor, $fileName, $validatedFile['digest'], $disk);
        }

        // Prepend root folder for actual storage path; DB keeps the clean folder value
        $storageFolder = $this->pathResolver->storageFolder($folder, $disk);

        // Store file on disk first — cleaned up on any failure below
        $visibility = $isPublic ? MediaVisibility::Public : MediaVisibility::Private;
        $stored = $this->files->store($file, $disk, $storageFolder, $hash, $visibility);

        if ($stored === false) {
            throw new MediaUploadException("Failed to store file [{$fileName}] on disk [{$disk}].");
        }

        $stored_path = $storageFolder ? $storageFolder.'/'.$hash : $hash;

        $writeCommitted = false;
        try {
            $storedDigest = $this->disks->checksum($disk, $stored_path);

            if (! hash_equals($validatedFile['digest'], $storedDigest)) {
                $this->packageLogger->log('media', 'error', 'nvl.media.upload_integrity.failed', [
                    'disk' => $disk,
                    'path' => $stored_path,
                    'mismatch' => 'checksum',
                    'expected_checksum' => $validatedFile['digest'],
                    'actual_checksum' => $storedDigest,
                ], 'normal');

                throw new MediaUploadException(
                    "Checksum verification failed for uploaded media [{$fileName}].",
                );
            }

            $storedSize = $this->disks->size($disk, $stored_path);

            if ($storedSize !== $validatedFile['size']) {
                $this->packageLogger->log('media', 'error', 'nvl.media.upload_integrity.failed', [
                    'disk' => $disk,
                    'path' => $stored_path,
                    'mismatch' => 'size',
                    'expected_size' => $validatedFile['size'],
                    'actual_size' => $storedSize,
                ], 'normal');

                throw new MediaUploadException(
                    "Size verification failed for uploaded media [{$fileName}].",
                );
            }

            $media = (MediaModelRegistry::newInstance())->getConnection()->transaction(function () use ($validatedFile, $disk, $isPublic, $fileName, $hash, $folder, $tags, $metadata, $uploaderId, $uploaderType, $stored_path, $variationDefinitions, $skipAutoVariations, &$writeCommitted): Media {
                $this->fileEffects->deleteAfterRollback(
                    $disk,
                    [$stored_path],
                    'upload_new_object',
                );

                $pathIdentity = MediaOwnership::active() ? ['storage_path' => $stored_path] : [];

                $created = MediaModelRegistry::create([
                    ...$pathIdentity,
                    'filename' => $fileName,
                    'hash' => $hash,
                    'extension' => $validatedFile['extension'],
                    'mime_type' => $validatedFile['mimeType'],
                    'size' => $validatedFile['size'],
                    'disk' => $disk,
                    'folder' => $folder,
                    'is_public' => $isPublic,
                    'visibility' => $isPublic
                        ? MediaVisibility::Public
                        : MediaVisibility::Private,
                    'status' => MediaLifecycleStatus::Available,
                    'available_at' => now(),
                    'type' => $validatedFile['type'],
                    'digest' => $validatedFile['digest'],
                    'tags' => ! empty($tags) ? $tags : null,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                    'variation_definitions' => $variationDefinitions !== []
                        ? $variationDefinitions
                        : null,
                    'uploaded_by' => $uploaderId,
                    'uploaded_by_type' => $uploaderType,
                ]);

                $this->eventCommits->afterCommit((MediaModelRegistry::newInstance())->getConnection(), static function () use (&$writeCommitted): void {
                    $writeCommitted = true;
                });
                if (! $skipAutoVariations) {
                    $this->variationDispatcher->dispatchConfiguredForUpload($created);
                }
                $this->domainEvents->dispatch(new MediaUploaded($created->id), $created->getConnection());

                return $created;
            });
        } catch (Throwable $e) {
            if ($writeCommitted) {
                throw $e;
            }

            $this->fileEffects->deleteNow(
                $disk,
                [$stored_path],
                'upload_pre_commit_failure',
            );

            throw $e;
        }

        return $media;
    }

    /**
     * Merge compatible named definitions onto a shared deduplicated asset.
     *
     * @param  array<string, array<string, mixed>>  $requestedDefinitions
     */
    private function uploadReuseDeduplicatedMedia(Authenticatable|SystemActor $actor,
        Media $media,
        array $requestedDefinitions,
        bool $skipAutoVariations,
    ): Media {
        $resolved = $requestedDefinitions === []
            ? $media
            : $this->mutationLock->execute(
                $media->id,
                function () use ($media, $requestedDefinitions): Media {
                    return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($media, $requestedDefinitions): Media {
                        $locked = MediaModelRegistry::query()->lockForUpdate()->findOrFail($media->id);
                        $existingDefinitions = is_array($locked->variation_definitions)
                            ? $locked->variation_definitions
                            : [];

                        foreach ($requestedDefinitions as $label => $definition) {
                            $existing = $existingDefinitions[$label] ?? null;

                            if (is_array($existing) && $existing !== $definition) {
                                throw new MediaUploadException(
                                    "Deduplicated media [{$media->id}] already has a conflicting variation definition [{$label}].",
                                );
                            }

                            $existingDefinitions[$label] = $definition;
                        }

                        ksort($existingDefinitions);
                        $locked->variation_definitions = $existingDefinitions;
                        $locked->save();

                        return $locked;
                    });
                },
            );

        if (! $skipAutoVariations) {
            $this->variationDispatcher->dispatchMissingConfiguredForUpload($resolved);
        }

        return $resolved;
    }

    /**
     * Generate a display filename for duplicate uploads.
     */
    protected function uploadGenerateDuplicateFileName(Authenticatable|SystemActor $actor, string $fileName, string $digest, string $disk): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $basename = pathinfo($fileName, PATHINFO_FILENAME);

        $sequence = MediaModelRegistry::query()->where('digest', $digest)
            ->where('disk', $disk)
            ->count() + 1;

        $random = Str::random(8);

        return "{$basename}-{$sequence}{$random}.{$extension}";
    }

    /**
     * Find a dedup candidate matching digest, disk, and visibility scope.
     *
     * - Public files dedup across all public files on the same disk.
     * - Private files dedup only within the same user's uploads.
     */
    protected function uploadFindDeduplicateCandidate(Authenticatable|SystemActor $actor,
        string $digest,
        string $disk,
        bool $isPublic,
        ?string $uploadedBy,
        ?string $uploadedByType,
    ): ?Media {
        $query = MediaModelRegistry::query()->where('digest', $digest)
            ->where('disk', $disk)
            ->where('is_public', $isPublic)
            ->available();

        if (! $isPublic) {
            $query
                ->where('uploaded_by', $uploadedBy)
                ->where('uploaded_by_type', $uploadedByType);
        }

        foreach ($query->orderBy('id')->get() as $candidate) {
            if ($this->existence->existsFresh($candidate->disk, $candidate->buildPath())) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Create the replacement action.
     */

    /**
     * Replace the original file and regenerate variations for the committed media state.
     *
     * @throws Throwable
     */
    public function replace(Authenticatable|SystemActor $actor, Media|string $media, UploadedFile $file): Media
    {
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Mutate, MediaModelRegistry::query()->findOrFail($media instanceof Media ? $media->id : $media));
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
                        [$staged['path']],
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
                        'filename' => $staged['validatedFile']['displayFilename'],
                        'hash' => $staged['hash'],
                        'extension' => $staged['validatedFile']['extension'],
                        'mime_type' => $staged['validatedFile']['mimeType'],
                        'size' => $staged['validatedFile']['size'],
                        'type' => $staged['validatedFile']['type'],
                        'digest' => $staged['validatedFile']['digest'],
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
                    [$staged['path']],
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
