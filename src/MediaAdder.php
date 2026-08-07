<?php

declare(strict_types=1);

namespace Nvl\Media;

use Closure;
use DateTimeInterface;
use finfo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Contracts\UploadMediaContract;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\ImageOptimizationService;
use Nvl\Media\Services\MediaLifecycleService;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaOwnedSourceLifecycle;
use Nvl\Media\Services\MediaTemporaryFileRegistry;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\FileNameSanitizer;
use Throwable;

/** MediaAdder: fluent builder for uploading, attaching, and generating variations on a model. */
class MediaAdder
{
    public ?string $pendingSlot = null;

    protected ?string $collectionOverride = null;

    protected ?string $fileName = null;

    protected ?Closure $fileNameSanitizer = null;

    protected bool $preserveOriginal = false;

    /** @var array<string, mixed> */
    protected array $customProperties = [];

    /** @var array<int, string> */
    protected array $tags = [];

    protected ?string $locale = null;

    protected ?int $order = null;

    protected ?string $diskOverride = null;

    protected ?string $folderOverride = null;

    protected ?bool $publicOverride = null;

    /** @var array<string, ConversionDefinition|array<string, mixed>>|null */
    protected ?array $variationOverrides = null;

    protected bool $skipVariations = false;

    /** @var array<string, mixed> */
    protected array $associationMeta = [];

    protected bool $allowDuplicates = false;

    protected ?string $uploaderId = null;

    protected ?string $uploaderType = null;

    /**
     * @return void
     */
    public function __construct(
        protected Model&HasMedia $model,
        protected UploadedFile|string $file,
        protected ImageOptimizationService $imageOptimizationService,
        protected UploadMediaContract $uploadMediaAction,
        protected AttachMediaContract $attachMediaAction,
        protected MediaLifecycleService $lifecycleService,
        protected MediaMutationLock $mutationLock,
        protected MediaOwnedSourceLifecycle $ownedSourceLifecycle,
        protected MediaTemporaryFileRegistry $temporaryFiles,
    ) {}

    public function usingSlot(string $slot): static
    {
        $this->pendingSlot = $slot;

        return $this;
    }

    public function preservingOriginal(bool $preserve = true): static
    {
        $this->preserveOriginal = $preserve;

        return $this;
    }

    public function usingFileName(string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function sanitizingFileName(Closure $sanitizer): static
    {
        $this->fileNameSanitizer = $sanitizer;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function withCustomProperties(array $properties): static
    {
        $this->customProperties = array_merge($this->customProperties, $properties);

        return $this;
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function withTags(array $tags): static
    {
        $this->tags = array_merge($this->tags, $tags);

        return $this;
    }

    public function tags(string ...$tags): static
    {
        $this->tags = array_merge($this->tags, array_values($tags));

        return $this;
    }

    public function toLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function withOrder(int $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function toDisk(string $disk): static
    {
        $this->diskOverride = $disk;

        return $this;
    }

    public function toFolder(string $folder): static
    {
        $this->folderOverride = $folder;

        return $this;
    }

    public function asPublic(bool $public = true): static
    {
        $this->publicOverride = $public;

        return $this;
    }

    /**
     * @param  array<string, ConversionDefinition|array<string, mixed>>  $variations
     */
    public function withVariations(array $variations): static
    {
        $this->variationOverrides = $variations;

        return $this;
    }

    public function withoutVariations(): static
    {
        $this->skipVariations = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withAssociationMeta(array $meta): static
    {
        $this->associationMeta = array_merge($this->associationMeta, $meta);

        return $this;
    }

    public function allowingDuplicates(bool $allow = true): static
    {
        $this->allowDuplicates = $allow;

        return $this;
    }

    /**
     * Attribute the upload to an explicit authenticatable model.
     *
     * @throws InvalidArgumentException When the uploader has no string or integer identifier
     */
    public function uploadedBy(Model&Authenticatable $uploader): static
    {
        $identifier = $uploader->getAuthIdentifier();

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new InvalidArgumentException(
                'Explicit media uploader must have a string or integer authentication identifier.',
            );
        }

        $this->uploaderId = (string) $identifier;
        $this->uploaderType = $uploader->getMorphClass();

        return $this;
    }

    /**
     * Fluent setter for the collection name stored in the pivot.
     */
    public function toCollection(string $collection): static
    {
        $this->collectionOverride = $collection;

        return $this;
    }

    /**
     * Terminal method: upload and attach the file to the resolved slot.
     *
     * @param  string|null  $name  Slot name (falls back to pendingSlot, then 'default')
     * @param  string|null  $disk  Override disk for this upload
     */
    public function slot(?string $name = null, ?string $disk = null): Media
    {
        return $this->performUpload($name ?? $this->pendingSlot ?? 'default', $disk);
    }

    public function slotOnCloudDisk(?string $name = null): Media
    {
        $configuredDisk = config('filesystems.cloud', 's3');
        $cloudDisk = is_string($configuredDisk) ? $configuredDisk : 's3';

        return $this->slot($name, $cloudDisk);
    }

    public function upload(): Media
    {
        return $this->slot();
    }

    /**
     * Execute the upload, attach, and dispatch pipeline.
     *
     * @param  string  $slotName  Resolved slot name
     * @param  string|null  $disk  Override disk
     */
    private function performUpload(string $slotName, ?string $disk = null): Media
    {
        if ($disk !== null) {
            $this->diskOverride = $disk;
        }

        $media = null;

        try {
            $slotConfig = $this->resolveSlotConfig($slotName);
            $uploadedFile = $this->resolveUploadedFile();
            $sourceTemporaryPath = $this->trackedPath($uploadedFile);
            $uploadedFile = $this->imageOptimizationService->optimize($uploadedFile, $slotConfig);
            $optimizedTemporaryPath = $this->trackedPath($uploadedFile);
            $media = $this->executeUpload($uploadedFile, $slotConfig, $slotName);
            $attachAndEnforceLimit = function () use ($media, $slotConfig, $slotName): Media {
                $this->attachToModel($media, $slotName);
                $this->enforceSlotSizeLimit($slotConfig, $media, $slotName);

                return $media;
            };
            $media = $slotConfig->slotSizeLimit === null
                ? $attachAndEnforceLimit()
                : $this->mutationLock->executeForOwnerCollection(
                    $this->model,
                    $this->collectionName($slotName),
                    fn (): Media => DB::transaction($attachAndEnforceLimit),
                );
        } catch (Throwable $exception) {
            if ($media instanceof Media && ! $media->associations()->exists()) {
                $this->lifecycleService->deleteMedia($media);
            }

            throw $exception;
        } finally {
            foreach (array_unique(array_filter([
                $sourceTemporaryPath ?? null,
                $optimizedTemporaryPath ?? null,
            ])) as $temporaryPath) {
                $this->temporaryFiles->release($temporaryPath);
            }
        }

        if (! $this->preserveOriginal && is_string($this->file)) {
            $this->ownedSourceLifecycle->deleteAfterCommit($this->file);
        }

        return $media;
    }

    /**
     * Store the file on disk and persist the Media record.
     */
    private function executeUpload(UploadedFile $uploaded_file, MediaSlot $slot_config, string $slotName): Media
    {
        $resolved_disk = $this->diskOverride ?? $slot_config->disk;
        $is_public = $this->publicOverride ?? $slot_config->isPublic;
        $all_tags = array_values(array_unique(array_merge(
            $slot_config->defaultTags,
            $this->tags,
        )));
        $should_deduplicate = ! $this->allowDuplicates && $slot_config->isShared();

        return $this->uploadMediaAction->execute(
            file: $uploaded_file,
            disk: $resolved_disk,
            model: $this->model,
            slot: $slot_config,
            fileName: $this->resolveFileName($uploaded_file),
            isPublic: $is_public,
            tags: $all_tags,
            metadata: $this->customProperties,
            folderOverride: $this->folderOverride,
            allowDuplicates: $this->allowDuplicates,
            deduplicateExisting: $should_deduplicate,
            skipAutoVariations: true,
            uploadedBy: $this->uploaderId,
            uploadedByType: $this->uploaderType,
            variationDefinitions: $this->variationOverrides ?? [],
        );
    }

    /**
     * Attach the media record to the model via the polymorphic pivot.
     */
    private function attachToModel(Media $media, string $slotName): void
    {
        $associationMeta = array_merge($this->associationMeta, [
            'slot' => $slotName,
        ]);

        $this->attachMediaAction->execute(
            media: $media,
            model: $this->model,
            collection: $this->collectionName($slotName),
            locale: $this->locale,
            order: $this->order,
            metadata: $associationMeta,
            dispatchVariations: ! $this->skipVariations,
        );
    }

    protected function resolveSlotConfig(string $slotName): MediaSlot
    {
        $config = $this->model->getMediaSlot($slotName);

        if ($config === null) {
            $config = new MediaSlot($slotName);
        }

        return $config;
    }

    protected function resolveUploadedFile(): UploadedFile
    {
        if ($this->file instanceof UploadedFile) {
            return $this->file;
        }

        $path = $this->file;

        if (! is_file($path) || ! is_readable($path)) {
            throw new MediaUploadException("Local media source [{$path}] is not readable.");
        }

        $name = basename($path);
        $mime_type = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        return new UploadedFile($path, $name, $mime_type, null, true);
    }

    /**
     * Return the path only when it belongs to the package temporary-file registry.
     */
    private function trackedPath(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        return is_string($path) && $this->temporaryFiles->owns($path) ? $path : null;
    }

    protected function resolveFileName(UploadedFile $file): string
    {
        if ($this->fileName !== null) {
            return $this->fileName;
        }

        $filename = $file->getClientOriginalName();

        if ($this->fileNameSanitizer !== null) {
            /** @var string $sanitized */
            $sanitized = ($this->fileNameSanitizer)($filename);

            return $sanitized;
        }

        return FileNameSanitizer::sanitize($filename);
    }

    protected function enforceSlotSizeLimit(MediaSlot $slot, Media $uploadedMedia, string $slotName): void
    {
        if ($slot->slotSizeLimit === null) {
            return;
        }

        $collection = $this->collectionName($slotName);
        $this->model->unsetRelation('media');
        $existing = $this->model->getMedia($collection);
        $excess_count = $existing->count() - $slot->slotSizeLimit;

        if ($excess_count > 0) {
            $to_remove = $existing
                ->reject(static fn (Media $media): bool => $media->is($uploadedMedia))
                ->sortBy(fn (Media $media): string => $this->slotOrderingKey($media))
                ->take($excess_count);

            foreach ($to_remove as $media) {
                $this->lifecycleService->removeFromModel(
                    model: $this->model,
                    media: $media,
                    collection: $collection,
                );
            }

            $this->model->unsetRelation('media');
        }
    }

    /**
     * Order uploads deterministically when database timestamp precision ties.
     *
     * Media uses UUIDv7 identifiers, so its key preserves insertion order when
     * multiple associations share the same persisted creation timestamp.
     */
    private function slotOrderingKey(Media $media): string
    {
        $pivot = $media->getRelationValue('pivot');
        $createdAt = null;

        if ($pivot instanceof Pivot) {
            $createdAt = $pivot->getAttribute('created_at');
        }

        $createdAt ??= $media->created_at;
        $timestamp = $createdAt instanceof DateTimeInterface
            ? $createdAt->format('Y-m-d H:i:s.uP')
            : (is_string($createdAt) ? $createdAt : '');

        return $timestamp."\0".$media->id;
    }

    private function collectionName(string $slotName): string
    {
        return $this->collectionOverride ?? $slotName;
    }
}
