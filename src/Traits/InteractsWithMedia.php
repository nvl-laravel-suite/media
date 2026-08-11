<?php

declare(strict_types=1);

namespace Nvl\Media\Traits;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\MediaAdder;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Relations\StringMorphMany;
use Nvl\Media\Relations\StringMorphToMany;
use Nvl\Media\Services\MediaLifecycleService;
use Nvl\Media\Services\MediaModelInteractionService;
use Nvl\Media\Slots\MediaSlot;

/** InteractsWithMedia: provides media attachment, retrieval, and lifecycle management for models. */
trait InteractsWithMedia
{
    /** @var array<string, MediaSlot> */
    protected array $mediaSlots = [];

    /** @var array<string, ConversionDefinition> */
    protected array $mediaConversions = [];

    protected bool $deletePreservingMediaFlag = false;

    private ?MediaModelInteractionService $mediaInteractionServiceInstance = null;

    private ?MediaLifecycleService $mediaLifecycleServiceInstance = null;

    protected function mediaInteractionService(): MediaModelInteractionService
    {
        return $this->mediaInteractionServiceInstance ??= app(MediaModelInteractionService::class);
    }

    protected function mediaLifecycleService(): MediaLifecycleService
    {
        return $this->mediaLifecycleServiceInstance ??= app(MediaLifecycleService::class);
    }

    /* ---------------------------------------------------------------
     * Boot & Lifecycle Hooks
     * ------------------------------------------------------------- */

    /**
     * Boot lifecycle hooks for media management.
     *
     * - **Force-delete**: all media records and files are permanently deleted.
     * - **Soft-delete**: media associations are preserved (not detached) so that restoring the
     *   model also restores its media references.
     */
    public static function bootInteractsWithMedia(): void
    {
        static::deleted(function (self $model) {
            if ($model->shouldDeletePreservingMedia()) {
                return;
            }

            if (! config('media.delete_media_on_model_delete', true)) {
                return;
            }

            if ($model->mediaOwnerIsSoftDeleting()) {
                return;
            }

            $model->deleteAllMedia();
        });
    }

    protected function shouldDeletePreservingMedia(): bool
    {
        return $this->deletePreservingMediaFlag;
    }

    /**
     * Determine whether the current delete event is a soft delete.
     *
     * @return bool True when the model uses SoftDeletes and is not force deleting
     */
    protected function mediaOwnerIsSoftDeleting(): bool
    {
        return $this->mediaInteractionService()->ownerIsSoftDeleting($this);
    }

    public function deletePreservingMedia(): ?bool
    {
        $this->deletePreservingMediaFlag = true;

        return $this->delete();
    }

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    /**
     * @return MorphToMany<Media, covariant Model>
     */
    public function media(): MorphToMany
    {
        $related = new Media;

        return (new StringMorphToMany(
            $related->newQuery(),
            $this,
            'associable',
            MediaTables::Associations,
            'associable_id',
            'media_id',
            $this->getKeyName(),
            $related->getKeyName(),
            'media',
        ))
            ->withPivot('collection', 'locale', 'order', 'metadata')
            ->withTimestamps()
            ->orderBy(MediaTables::Associations.'.order');
    }

    /**
     * @return MorphMany<MediaAssociation, $this>
     */
    public function mediaAssociations(): MorphMany
    {
        $related = new MediaAssociation;

        return new StringMorphMany(
            $related->newQuery(),
            $this,
            $related->qualifyColumn('associable_type'),
            $related->qualifyColumn('associable_id'),
            $this->getKeyName(),
        );
    }

    /* ---------------------------------------------------------------
     * Slot & Conversion Registration
     * ------------------------------------------------------------- */

    public function registerMediaSlots(): void
    {
        // Override in model
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Override in model
    }

    public function addMediaSlot(string $name): MediaSlot
    {
        $slot = new MediaSlot($name);
        $this->mediaSlots[$name] = $slot;

        return $slot;
    }

    public function addMediaConversion(string $name): ConversionDefinition
    {
        $definition = new ConversionDefinition($name);
        $this->mediaConversions[$name] = $definition;

        return $definition;
    }

    /**
     * @return array<string, ConversionDefinition>
     */
    public function getModelConversions(): array
    {
        return $this->mediaConversions;
    }

    public function getMediaSlot(string $name = 'default'): ?MediaSlot
    {
        $this->ensureSlotsRegistered();

        return $this->mediaSlots[$name] ?? null;
    }

    /**
     * @return Collection<string, MediaSlot>
     */
    public function getRegisteredMediaSlots(): Collection
    {
        $this->ensureSlotsRegistered();

        return collect($this->mediaSlots);
    }

    protected function ensureSlotsRegistered(): void
    {
        if (empty($this->mediaSlots)) {
            $this->registerMediaSlots();
        }
    }

    /* ---------------------------------------------------------------
     * Upload Source Methods (delegate to MediaSourceResolver)
     * ------------------------------------------------------------- */

    public function addMedia(string|UploadedFile $file, ?string $slot = null): MediaAdder
    {
        return $this->mediaInteractionService()->addMedia($this, $file, $slot);
    }

    public function copyMedia(string|UploadedFile $file): MediaAdder
    {
        return $this->mediaInteractionService()->copyMedia($this, $file);
    }

    public function addMediaFromRequest(string $key): MediaAdder
    {
        return $this->mediaInteractionService()->addMediaFromRequest($this, $key);
    }

    public function addMediaFromUrl(string $url, string ...$allowedMimeTypes): MediaAdder
    {
        return $this->mediaInteractionService()->addMediaFromUrl($this, $url, ...$allowedMimeTypes);
    }

    public function addMediaFromBase64(string $base64data, string ...$allowedMimeTypes): MediaAdder
    {
        return $this->mediaInteractionService()->addMediaFromBase64($this, $base64data, ...$allowedMimeTypes);
    }

    public function addMediaFromString(string $text): MediaAdder
    {
        return $this->mediaInteractionService()->addMediaFromString($this, $text);
    }

    /**
     * Create an upload builder from generated binary content.
     */
    public function addMediaFromBinary(
        string $contents,
        string $filename,
        string ...$allowedMimeTypes,
    ): MediaAdder {
        return $this->mediaInteractionService()->addMediaFromBinary(
            $this,
            $contents,
            $filename,
            ...$allowedMimeTypes,
        );
    }

    public function addMediaFromDisk(string $key, ?string $disk = null): MediaAdder
    {
        return $this->mediaInteractionService()->addMediaFromDisk($this, $key, $disk);
    }

    /**
     * Reuse an existing public asset without uploading another file.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function reusePublicMedia(
        Media|string $media,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): Media {
        return $this->mediaInteractionService()->reusePublicMedia(
            model: $this,
            media: $media,
            collection: $collection,
            locale: $locale,
            order: $order,
            metadata: $metadata,
            dispatchVariations: $dispatchVariations,
        );
    }

    /* ---------------------------------------------------------------
     * Retrieval Methods
     * ------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>|callable  $filters
     * @return Collection<int, Media>
     */
    public function getMedia(string $collection = 'default', array|callable $filters = []): Collection
    {
        // Use already-loaded relation when available to avoid N+1 queries.
        if ($this->relationLoaded('media')) {
            $media = $this->mediaCollectionFromRelation($this->getRelation('media'))
                ->filter(static fn (Media $media): bool => self::mediaBelongsToCollection($media, $collection))
                ->values();
        } else {
            $media = $this->mediaCollectionFromRelation($this->media()
                ->wherePivot('collection', $collection)
                ->get());
        }

        if (empty($filters)) {
            return $media;
        }

        if (is_callable($filters)) {
            return $media->filter($filters)->values();
        }

        return $media->filter(function (Media $item) use ($filters) {
            foreach ($filters as $key => $value) {
                if ($key === 'tags' && is_string($value) && $item->hasTag($value)) {
                    continue;
                }

                if ($item->getAttribute($key) !== $value) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  array<string, mixed>|callable  $filters
     */
    public function getFirstMedia(string $collection = 'default', array|callable $filters = []): ?Media
    {
        return $this->getMedia($collection, $filters)->first();
    }

    /**
     * @param  array<string, mixed>|callable  $filters
     */
    public function getLastMedia(string $collection = 'default', array|callable $filters = []): ?Media
    {
        return $this->getMedia($collection, $filters)->last();
    }

    /**
     * @param  array<string, mixed>|callable  $filters
     */
    public function hasMedia(string $collection = 'default', array|callable $filters = []): bool
    {
        if (empty($filters) && $this->relationLoaded('media')) {
            return $this->mediaCollectionFromRelation($this->getRelation('media'))
                ->contains(static fn (Media $media): bool => self::mediaBelongsToCollection($media, $collection));
        }

        if (empty($filters)) {
            return $this->media()
                ->wherePivot('collection', $collection)
                ->exists();
        }

        return $this->getMedia($collection, $filters)->isNotEmpty();
    }

    /**
     * @return Collection<int, Media>
     */
    private function mediaCollectionFromRelation(mixed $relation): Collection
    {
        if (! $relation instanceof Collection) {
            return new Collection;
        }

        $mediaItems = [];

        foreach ($relation as $media) {
            if ($media instanceof Media) {
                $mediaItems[] = $media;
            }
        }

        return new Collection($mediaItems);
    }

    private static function mediaBelongsToCollection(Media $media, string $collection): bool
    {
        $pivot = $media->getAttribute('pivot');

        if (! $pivot instanceof Pivot) {
            return false;
        }

        return $pivot->getAttribute('collection') === $collection;
    }

    /* ---------------------------------------------------------------
     * URL Convenience Methods
     * ------------------------------------------------------------- */

    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->getFirstMedia($collection);

        if ($media === null) {
            return $this->getFallbackMediaUrl($collection, $conversion);
        }

        return $media->buildUrl(['v' => $conversion !== '' ? $conversion : null]);
    }

    public function getLastMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->getLastMedia($collection);

        if ($media === null) {
            return $this->getFallbackMediaUrl($collection, $conversion);
        }

        return $media->buildUrl(['v' => $conversion !== '' ? $conversion : null]);
    }

    public function getFirstMediaPath(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->getFirstMedia($collection);

        if ($media === null) {
            return $this->getFallbackMediaPath($collection, $conversion);
        }

        return $media->getPath($conversion);
    }

    public function getLastMediaPath(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->getLastMedia($collection);

        if ($media === null) {
            return $this->getFallbackMediaPath($collection, $conversion);
        }

        return $media->getPath($conversion);
    }

    /**
     * Build a centralized URL for the first media item in a collection.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildUrl(
        string $collection = 'default',
        array $parameters = [],
        ?DateTimeInterface $expiration = null,
    ): string {
        $media = $this->getFirstMedia($collection);

        if ($media === null) {
            return $this->getFallbackMediaUrl($collection, (string) ($parameters['v'] ?? ''));
        }

        return $media->buildUrl($parameters, $expiration);
    }

    /**
     * Build a centralized public URL for the first media item in a collection.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildPublicUrl(string $collection = 'default', array $parameters = []): string
    {
        $media = $this->getFirstMedia($collection);

        if ($media === null) {
            return $this->getFallbackMediaUrl($collection, (string) ($parameters['v'] ?? ''));
        }

        return $media->buildPublicUrl($parameters);
    }

    public function getFirstTemporaryUrl(
        DateTimeInterface $expiration,
        string $collection = 'default',
        string $conversion = '',
    ): string {
        $media = $this->getFirstMedia($collection);

        if ($media === null) {
            return '';
        }

        return $media->getTemporaryUrl($expiration, $conversion);
    }

    public function getFallbackMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        $config = $this->getMediaSlot($collection);

        return $config?->getFallbackUrl($conversion) ?? '';
    }

    public function getFallbackMediaPath(string $collection = 'default', string $conversion = ''): string
    {
        $config = $this->getMediaSlot($collection);

        return $config?->getFallbackPath($conversion) ?? '';
    }

    /* ---------------------------------------------------------------
     * Lifecycle Methods (delegate to MediaLifecycleService)
     * ------------------------------------------------------------- */

    public function clearMediaCollection(string $collection = 'default'): static
    {
        $this->mediaLifecycleService()->clearCollection($this, $collection);

        return $this;
    }

    /**
     * @param  array<int, Media|string>|Collection<int, Media|string>  $except
     */
    public function clearMediaCollectionExcept(string $collection = 'default', array|Collection $except = []): static
    {
        $this->mediaLifecycleService()->clearCollectionExcept($this, $collection, $except);

        return $this;
    }

    public function deleteMedia(string|Media $media): void
    {
        $this->mediaLifecycleService()->deleteMedia($media);
    }

    public function deleteAllMedia(): static
    {
        $this->mediaLifecycleService()->deleteAll($this);

        return $this;
    }

    public function detachMedia(string|Media $media, ?string $collection = null): void
    {
        $this->mediaLifecycleService()->detach($media, $this, $collection);
    }

    /* ---------------------------------------------------------------
     * Order & Utility
     * ------------------------------------------------------------- */

    /**
     * Batch-update media ordering for a collection using a single query.
     *
     * @param  array<int, string>  $ordered_ids  Media UUIDs in desired order (index = order value)
     */
    public function updateMediaOrder(array $ordered_ids, string $collection = 'default'): void
    {
        if (empty($ordered_ids)) {
            return;
        }

        $cases = [];
        $bindings = [];
        $association = new MediaAssociation;
        $connection = $association->getConnection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($association->getTable());
        $mediaId = $grammar->wrap('media_id');
        $orderColumn = $grammar->wrap('order');

        foreach ($ordered_ids as $order => $media_id) {
            $cases[] = "WHEN {$mediaId} = ? THEN ?";
            $bindings[] = $media_id;
            $bindings[] = (int) $order;
        }

        $caseStatement = implode(' ', $cases);

        $connection->update(
            "UPDATE {$table}"
            ." SET {$orderColumn} = CASE {$caseStatement} ELSE {$orderColumn} END"
            .' WHERE '.$grammar->wrap('associable_type').' = ?'
            .' AND '.$grammar->wrap('associable_id').' = ?'
            .' AND '.$grammar->wrap('collection').' = ?'
            .' AND '.$mediaId.' IN ('.implode(',', array_fill(0, count($ordered_ids), '?')).')',
            array_merge($bindings, [$this->getMorphClass(), $this->getKey(), $collection], $ordered_ids),
        );
    }

    public function getMediaModel(): string
    {
        return Media::class;
    }
}
