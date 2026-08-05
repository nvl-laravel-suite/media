<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\UploadMediaContract;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Data\Ingestion\ValidatedMediaFileData;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Events\MediaUploadedEvent;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDeduplicationLock;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaIngestionPipeline;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaVariationDefinitionNormalizer;
use Nvl\Media\Services\MediaVariationDispatcher;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaHashGenerator;
use Throwable;

/**
 * Stores uploaded files on disk and persists Media records with deduplication.
 */
final class UploadMediaAction implements UploadMediaContract
{
    public function __construct(
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
    ) {}

    /**
     * Upload a file to the given disk and persist the Media record.
     *
     * @param  string[]  $tags  Flat list of classification tags
     * @param  array<string, mixed>  $metadata  Arbitrary key-value pairs stored with the media
     * @param  string|null  $folderOverride  Override the resolved storage folder
     * @param  bool|null  $allowDuplicates  Force duplicate row creation and duplicate-safe filenames
     * @param  bool|null  $deduplicateExisting  Reuse an existing media row when one already exists for the same digest
     * @param  bool  $skipAutoVariations  Skip preset/output conversion generation for this upload
     * @param  string|null  $uploadedBy  Explicit uploader identifier; defaults to the authenticated actor
     * @param  string|null  $uploadedByType  Explicit uploader morph type; defaults to the authenticated model type
     * @param  array<string, ConversionDefinition|array<string, mixed>>  $variationDefinitions
     * @return Media The freshly created Media model
     *
     * @throws MediaUploadException When the file cannot be stored on disk
     */
    public function execute(
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
        ?string $uploadedBy = null,
        ?string $uploadedByType = null,
        array $variationDefinitions = [],
    ): Media {
        $this->diskGuard->assertAllowed($disk);
        $this->disks->ensureDefined($disk);
        $validatedFile = $this->ingestion->inspect($file, [$slot], $fileName);
        $normalizedVariationDefinitions = $this->definitionNormalizer->normalize($variationDefinitions);
        $authenticatedUser = auth()->user();
        $authenticatedId = $authenticatedUser?->getAuthIdentifier();
        $uploaderId = $uploadedBy
            ?? (is_int($authenticatedId) || is_string($authenticatedId) ? (string) $authenticatedId : null);
        $uploaderType = $uploadedByType;

        if ($uploaderType === null && $authenticatedUser instanceof Model) {
            $uploaderType = $authenticatedUser->getMorphClass();
        }

        $folder = $folderOverride !== null
            ? $this->pathResolver->normalizeFolder($folderOverride)
            : $this->pathResolver->resolve($model, $slot);

        $duplicates_allowed = (bool) ($allowDuplicates ?? config('media.allow_duplicates', false));
        $should_deduplicate = $deduplicateExisting ?? ! $duplicates_allowed;

        if (! $isPublic && $uploaderId === null && ! config('media.deduplication.allow_anonymous_private', false)) {
            $should_deduplicate = false;
        }

        if ($should_deduplicate) {
            $existing = $this->findDeduplicateCandidate(
                $validatedFile->digest,
                $disk,
                $isPublic,
                $uploaderId,
                $uploaderType,
            );

            if ($existing !== null) {
                return $this->reuseDeduplicatedMedia(
                    $existing,
                    $normalizedVariationDefinitions,
                    $skipAutoVariations,
                );
            }

            return $this->deduplicationLock->execute(
                $validatedFile->digest,
                $disk,
                $isPublic,
                $uploaderId,
                $uploaderType,
                function () use ($file, $disk, $isPublic, $tags, $metadata, $folder, $duplicates_allowed, $validatedFile, $uploaderId, $uploaderType, $skipAutoVariations, $normalizedVariationDefinitions): Media {
                    $existing = $this->findDeduplicateCandidate(
                        $validatedFile->digest,
                        $disk,
                        $isPublic,
                        $uploaderId,
                        $uploaderType,
                    );

                    if ($existing !== null) {
                        return $this->reuseDeduplicatedMedia(
                            $existing,
                            $normalizedVariationDefinitions,
                            $skipAutoVariations,
                        );
                    }

                    return $this->storeUploadedMedia(
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

        $media = $this->storeUploadedMedia(
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
     * Store a validated upload on disk and persist a new Media row.
     *
     * @param  string[]  $tags
     * @param  array<string, mixed>  $metadata
     * @param  array<string, array<string, mixed>>  $variationDefinitions
     *
     * @throws MediaUploadException When the file cannot be stored on disk
     */
    private function storeUploadedMedia(
        UploadedFile $file,
        string $disk,
        bool $isPublic,
        array $tags,
        array $metadata,
        string $folder,
        bool $duplicatesAllowed,
        ValidatedMediaFileData $validatedFile,
        ?string $uploaderId,
        ?string $uploaderType,
        bool $skipAutoVariations,
        array $variationDefinitions,
    ): Media {
        $fileName = $validatedFile->displayFilename;
        $hash = MediaHashGenerator::generateForExtension($validatedFile->extension);

        if ($duplicatesAllowed) {
            $fileName = $this->generateDuplicateFileName($fileName, $validatedFile->digest, $disk);
        }

        // Prepend root folder for actual storage path; DB keeps the clean folder value
        $storageFolder = Media::storagePath($folder);

        // Store file on disk first — cleaned up on any failure below
        $visibility = $isPublic ? MediaVisibility::Public : MediaVisibility::Private;
        $stored = $this->files->store($file, $disk, $storageFolder, $hash, $visibility);

        if ($stored === false) {
            throw new MediaUploadException("Failed to store file [{$fileName}] on disk [{$disk}].");
        }

        $stored_path = $storageFolder ? $storageFolder.'/'.$hash : $hash;

        try {
            $storedDigest = $this->disks->checksum($disk, $stored_path);

            if (! hash_equals($validatedFile->digest, $storedDigest)) {
                Log::error('Media upload integrity verification failed.', [
                    'disk' => $disk,
                    'path' => $stored_path,
                    'mismatch' => 'checksum',
                    'expected_checksum' => $validatedFile->digest,
                    'actual_checksum' => $storedDigest,
                ]);

                throw new MediaUploadException(
                    "Checksum verification failed for uploaded media [{$fileName}].",
                );
            }

            $storedSize = $this->disks->size($disk, $stored_path);

            if ($storedSize !== $validatedFile->size) {
                Log::error('Media upload integrity verification failed.', [
                    'disk' => $disk,
                    'path' => $stored_path,
                    'mismatch' => 'size',
                    'expected_size' => $validatedFile->size,
                    'actual_size' => $storedSize,
                ]);

                throw new MediaUploadException(
                    "Size verification failed for uploaded media [{$fileName}].",
                );
            }

            $media = DB::transaction(function () use ($validatedFile, $disk, $isPublic, $fileName, $hash, $folder, $tags, $metadata, $uploaderId, $uploaderType, $stored_path, $variationDefinitions): Media {
                $this->fileEffects->deleteAfterRollback(
                    $disk,
                    [$stored_path],
                    'upload_new_object',
                );

                return Media::create([
                    'filename' => $fileName,
                    'hash' => $hash,
                    'extension' => $validatedFile->extension,
                    'mime_type' => $validatedFile->mimeType,
                    'size' => $validatedFile->size,
                    'disk' => $disk,
                    'folder' => $folder,
                    'is_public' => $isPublic,
                    'visibility' => $isPublic
                        ? MediaVisibility::Public
                        : MediaVisibility::Private,
                    'status' => MediaLifecycleStatus::Available,
                    'available_at' => now(),
                    'type' => $validatedFile->type,
                    'digest' => $validatedFile->digest,
                    'tags' => ! empty($tags) ? $tags : null,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                    'variation_definitions' => $variationDefinitions !== []
                        ? $variationDefinitions
                        : null,
                    'uploaded_by' => $uploaderId,
                    'uploaded_by_type' => $uploaderType,
                ]);
            });
        } catch (Throwable $e) {
            $this->fileEffects->deleteNow(
                $disk,
                [$stored_path],
                'upload_pre_commit_failure',
            );

            throw $e;
        }

        if (! $skipAutoVariations) {
            $this->variationDispatcher->dispatchConfiguredForUpload($media);
        }

        MediaUploadedEvent::dispatch($media);

        return $media;
    }

    /**
     * Merge compatible named definitions onto a shared deduplicated asset.
     *
     * @param  array<string, array<string, mixed>>  $requestedDefinitions
     */
    private function reuseDeduplicatedMedia(
        Media $media,
        array $requestedDefinitions,
        bool $skipAutoVariations,
    ): Media {
        $resolved = $requestedDefinitions === []
            ? $media
            : $this->mutationLock->execute(
                $media->id,
                function () use ($media, $requestedDefinitions): Media {
                    return DB::transaction(function () use ($media, $requestedDefinitions): Media {
                        $locked = Media::query()->lockForUpdate()->findOrFail($media->id);
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
    protected function generateDuplicateFileName(string $fileName, string $digest, string $disk): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $basename = pathinfo($fileName, PATHINFO_FILENAME);

        $sequence = Media::where('digest', $digest)
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
     *
     * @param  string|null  $uploadedBy  Uploader identifier used for private deduplication
     * @param  string|null  $uploadedByType  Uploader morph type used for private deduplication
     */
    protected function findDeduplicateCandidate(
        string $digest,
        string $disk,
        bool $isPublic,
        ?string $uploadedBy,
        ?string $uploadedByType,
    ): ?Media {
        $query = Media::where('digest', $digest)
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
}
