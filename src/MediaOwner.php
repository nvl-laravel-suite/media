<?php

declare(strict_types=1);

namespace Nvl\Media;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Relations\StringMorphToMany;
use Nvl\Media\Services\MediaLifecycleService;
use Nvl\Media\Services\MediaModelInteractionService;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Slots\MediaSlot;

/**
 * Provides the complete NVL media capability for one real Eloquent owner.
 *
 * @api
 */
final class MediaOwner
{
    private bool $registered = false;

    private bool $preservingOwnerMedia = false;

    private ?MediaModelInteractionService $interactions = null;

    private ?MediaLifecycleService $lifecycle = null;

    /** Retain the real owner and defer runtime collaborators until an operation needs them.
     * @param  Closure(): MediaModelInteractionService  $resolveInteractions
     * @param  Closure(): MediaLifecycleService  $resolveLifecycle
     */
    public function __construct(
        private readonly Model&HasNvlMedia $owner,
        private readonly Closure $resolveInteractions,
        private readonly Closure $resolveLifecycle,
        private readonly MediaRegistration $definitions,
    ) {}

    /**
     * Handle NVL owner deletion without touching third-party storage.
     *
     * @internal
     */
    public function ownerDeleted(): void
    {
        if (! $this->preservingOwnerMedia && config('nvl-media.delete_media_on_model_delete', true)
            && ! $this->interactions()->ownerIsSoftDeleting($this->owner)) {
            $this->deleteAll();
        }
    }

    /** Delete the owner while retaining only its NVL media associations. */
    public function deleteOwnerPreservingMedia(): ?bool
    {
        $this->preservingOwnerMedia = true;

        return $this->owner->delete();
    }

    /**
     * Return the independent NVL asset relation query.
     *
     * @return MorphToMany<Media, covariant Model>
     *
     * @internal
     */
    public function query(): MorphToMany
    {
        $related = MediaModelRegistry::newInstance();

        return (new StringMorphToMany(
            $related->newQuery(),
            $this->owner,
            'associable',
            MediaTables::get(MediaTables::Associations),
            'associable_id',
            'media_id',
            $this->owner->getKeyName(),
            $related->getKeyName(),
            'nvlMedia',
        ))
            ->withPivot('collection', 'locale', 'order', 'metadata')
            ->withTimestamps()
            ->orderBy(MediaTables::get(MediaTables::Associations).'.order');
    }

    /**
     * Return eager-loadable NVL association rows and their assets.
     *
     * @return MorphMany<MediaAssociation, covariant Model>
     *
     * @internal
     */
    public function associations(): MorphMany
    {
        return $this->owner->nvlMediaAssociations();
    }

    /* ---------------------------------------------------------------
     * Slot & Conversion Registration
     * ------------------------------------------------------------- */

    /** Return one registered slot or null when it is unrestricted. */
    public function slot(string $name = 'default'): ?MediaSlot
    {
        return $this->registration()->getSlot($name);
    }

    /** Return registered slot definitions.
     * @return Collection<string, MediaSlot>
     */
    public function slots(): Collection
    {
        return $this->registration()->slots();
    }

    /** Return owner conversion definitions for the optional asset.
     * @return array<string, ConversionDefinition>
     */
    public function conversions(?Media $media = null): array
    {
        return $this->registration($media)->conversions();
    }

    /** Return registration definitions, invoking the owner's namespaced hook. */
    public function registration(?Media $media = null): MediaRegistration
    {
        if (! $this->registered || $media !== null) {
            $this->registered = true;
            $this->owner->registerNvlMedia($this->definitions, $media);
        }

        return $this->definitions;
    }

    /* ---------------------------------------------------------------
     * Upload Source Methods (delegate to MediaSourceResolver)
     * ------------------------------------------------------------- */

    /** Build an NVL upload with an optional slot. */
    public function add(string|UploadedFile $file, ?string $slot = null): MediaAdder
    {
        return $this->interactions()->addMedia($this->owner, $file, $slot);
    }

    /** Build an upload that retains the caller-owned original. */
    public function copy(string|UploadedFile $file): MediaAdder
    {
        return $this->interactions()->copyMedia($this->owner, $file);
    }

    /** Build an upload from one request file. */
    public function fromRequest(string $key): MediaAdder
    {
        return $this->interactions()->addMediaFromRequest($this->owner, $key);
    }

    /** Build an upload through the bounded URL resolver. */
    public function fromUrl(string $url, string ...$allowedMimeTypes): MediaAdder
    {
        return $this->interactions()->addMediaFromUrl($this->owner, $url, ...$allowedMimeTypes);
    }

    /** Build an upload from bounded base64 content. */
    public function fromBase64(string $base64data, string ...$allowedMimeTypes): MediaAdder
    {
        return $this->interactions()->addMediaFromBase64($this->owner, $base64data, ...$allowedMimeTypes);
    }

    /** Build an upload from text content. */
    public function fromString(string $text): MediaAdder
    {
        return $this->interactions()->addMediaFromString($this->owner, $text);
    }

    /**
     * Create an upload builder from generated binary content.
     */
    public function fromBinary(
        string $contents,
        string $filename,
        string ...$allowedMimeTypes,
    ): MediaAdder {
        return $this->interactions()->addMediaFromBinary(
            $this->owner,
            $contents,
            $filename,
            ...$allowedMimeTypes,
        );
    }

    /** Build an upload from an existing disk object. */
    public function fromDisk(string $key, ?string $disk = null): MediaAdder
    {
        return $this->interactions()->addMediaFromDisk($this->owner, $key, $disk);
    }

    /**
     * Reuse an existing public asset without uploading another file.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function reuse(
        Media|string $media,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): Media {
        return $this->interactions()->reusePublicMedia(
            model: $this->owner,
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
     * Read a collection through NVL ownership and optional filters.
     *
     * @param  array<string, mixed>|callable  $filters
     * @return Collection<int, Media>
     */
    public function all(string $collection = 'default', array|callable $filters = []): Collection
    {
        $interactions = $this->interactions();
        $interactions->assertTenantAccess($this->owner);

        if ($this->owner->relationLoaded('nvlMediaAssociations')) {
            $media = $this->mediaFromAssociations($this->owner->getRelation('nvlMediaAssociations'), $collection);
        } elseif ($this->owner->relationLoaded('media') && $this->owner instanceof HasMedia) {
            $media = $this->mediaCollectionFromRelation($this->owner->getRelation('media'))
                ->filter(static fn (Media $media): bool => self::mediaBelongsToCollection($media, $collection))
                ->values();
        } else {
            $media = $this->mediaCollectionFromRelation($this->query()
                ->wherePivot('collection', $collection)
                ->get());
        }

        foreach ($media as $item) {
            $interactions->assertTenantAccess($this->owner, $item);
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
     * Return the first NVL asset in a collection.
     *
     * @param  array<string, mixed>|callable  $filters
     */
    public function first(string $collection = 'default', array|callable $filters = []): ?Media
    {
        return $this->all($collection, $filters)->first();
    }

    /**
     * Return the last NVL asset in a collection.
     *
     * @param  array<string, mixed>|callable  $filters
     */
    public function last(string $collection = 'default', array|callable $filters = []): ?Media
    {
        return $this->all($collection, $filters)->last();
    }

    /**
     * Determine whether the owner has an NVL asset in the collection.
     *
     * @param  array<string, mixed>|callable  $filters
     */
    public function has(string $collection = 'default', array|callable $filters = []): bool
    {
        $this->interactions()->assertTenantAccess($this->owner);

        if (empty($filters) && ($this->owner->relationLoaded('nvlMediaAssociations') || ($this->owner->relationLoaded('media') && $this->owner instanceof HasMedia))) {
            return $this->all($collection)->isNotEmpty();
        }

        if (empty($filters)) {
            return $this->query()
                ->wherePivot('collection', $collection)
                ->exists();
        }

        return $this->all($collection, $filters)->isNotEmpty();
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

    /** Return the first asset URL or the registered fallback. */
    public function firstUrl(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->first($collection);

        if ($media === null) {
            return $this->fallbackUrl($collection, $conversion);
        }

        return $media->buildUrl(['v' => $conversion !== '' ? $conversion : null]);
    }

    /** Return the last asset URL or the registered fallback. */
    public function lastUrl(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->last($collection);

        if ($media === null) {
            return $this->fallbackUrl($collection, $conversion);
        }

        return $media->buildUrl(['v' => $conversion !== '' ? $conversion : null]);
    }

    /** Return the first asset path or the registered fallback. */
    public function firstPath(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->first($collection);

        if ($media === null) {
            return $this->fallbackPath($collection, $conversion);
        }

        return $media->getPath($conversion);
    }

    /** Return the last asset path or the registered fallback. */
    public function lastPath(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->last($collection);

        if ($media === null) {
            return $this->fallbackPath($collection, $conversion);
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
        $media = $this->first($collection);

        if ($media === null) {
            return $this->fallbackUrl($collection, (string) ($parameters['v'] ?? ''));
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
        $media = $this->first($collection);

        if ($media === null) {
            return $this->fallbackUrl($collection, (string) ($parameters['v'] ?? ''));
        }

        return $media->buildPublicUrl($parameters);
    }

    /** Return a signed temporary URL for the first asset. */
    public function firstTemporaryUrl(
        DateTimeInterface $expiration,
        string $collection = 'default',
        string $conversion = '',
    ): string {
        $media = $this->first($collection);

        if ($media === null) {
            return '';
        }

        return $media->getTemporaryUrl($expiration, $conversion);
    }

    /** Resolve the registered fallback URL. */
    public function fallbackUrl(string $collection = 'default', string $conversion = ''): string
    {
        $config = $this->slot($collection);

        return $config?->getFallbackUrl($conversion) ?? '';
    }

    /** Resolve the registered fallback path. */
    public function fallbackPath(string $collection = 'default', string $conversion = ''): string
    {
        $config = $this->slot($collection);

        return $config?->getFallbackPath($conversion) ?? '';
    }

    /* ---------------------------------------------------------------
     * Lifecycle Methods (delegate to MediaLifecycleService)
     * ------------------------------------------------------------- */

    /** Clear one NVL collection through its lifecycle boundary. */
    public function clear(string $collection = 'default'): static
    {
        $this->lifecycle()->clearCollection($this->owner, $collection);
        $this->invalidate();

        return $this;
    }

    /**
     * Clear collection assets except the explicitly retained identifiers.
     *
     * @param  array<int, Media|string>|Collection<int, Media|string>  $except
     */
    public function clearExcept(string $collection = 'default', array|Collection $except = []): static
    {
        $this->lifecycle()->clearCollectionExcept($this->owner, $collection, $except);
        $this->invalidate();

        return $this;
    }

    /** Delete one NVL asset through the replaceable global delete Action. */
    public function delete(string|Media $media): void
    {
        $this->lifecycle()->deleteMedia($media);
    }

    /** Remove all NVL associations owned by this model. */
    public function deleteAll(): static
    {
        $this->lifecycle()->deleteAll($this->owner);
        $this->invalidate();

        return $this;
    }

    /** Detach an NVL asset without deleting its stored file. */
    public function detach(string|Media $media, ?string $collection = null): void
    {
        $this->lifecycle()->detach($media, $this->owner, $collection);
        $this->invalidate();
    }

    /* ---------------------------------------------------------------
     * Order & Utility
     * ------------------------------------------------------------- */

    /**
     * Batch-update media ordering for a collection using a single query.
     *
     * @param  array<int, string>  $ordered_ids  Media UUIDs in desired order (index = order value)
     */
    public function order(array $ordered_ids, string $collection = 'default'): void
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
            array_merge($bindings, [$this->owner->getMorphClass(), $this->owner->getKey(), $collection], $ordered_ids),
        );
    }

    /** Return the NVL asset model class. */
    public function modelClass(): string
    {
        return MediaModelRegistry::modelClass();
    }

    /** Attach an existing NVL asset through its owned association action.
     * @param  array<string, mixed>  $metadata
     */
    public function attach(Media $media, string $collection = 'default', ?string $locale = null, ?int $order = null, array $metadata = [], bool $dispatchVariations = true): MediaAssociation
    {
        $association = $this->interactions()->attachMedia($media, $this->owner, $collection, $locale, $order, $metadata, $dispatchVariations);
        $this->invalidate();

        return $association;
    }

    /** Synchronize associations without deleting reusable NVL assets.
     * @param  array<int, Media>|Collection<int, Media>  $media
     */
    public function sync(array|Collection $media, string $collection = 'default'): void
    {
        $this->lifecycle()->sync($this->owner, $media, $collection);
        $this->invalidate();
    }

    /**
     * Identify the real model instance when Eloquent clones an owner.
     *
     * @internal
     */
    public function owns(Model $owner): bool
    {
        return $owner === $this->owner;
    }

    /**
     * Invalidate only NVL reads after one of its mutations.
     *
     * @internal
     */
    public function invalidate(): void
    {
        $this->owner->unsetRelation('nvlMediaAssociations');
        if ($this->owner instanceof HasMedia) {
            $this->owner->unsetRelation('media');
        }
    }

    /** Read eager associations without inspecting a third-party media relation.
     * @return Collection<int, Media>
     */
    private function mediaFromAssociations(mixed $relation, string $collection): Collection
    {
        $items = [];
        if ($relation instanceof Collection) {
            foreach ($relation as $association) {
                if (! $association instanceof MediaAssociation || $association->collection !== $collection) {
                    continue;
                }
                $media = $association->media;
                if (! $media instanceof Media) {
                    continue;
                }
                $media = clone $media;
                $media->setRelation('pivot', Pivot::fromAttributes($this->owner, $association->getAttributes(), $association->getTable(), true));
                $items[] = $media;
            }
        }

        return new Collection($items);
    }

    /** Resolve runtime interactions without initializing them during definition registration. */
    private function interactions(): MediaModelInteractionService
    {
        return $this->interactions ??= ($this->resolveInteractions)();
    }

    /** Resolve file lifecycle operations only when a mutation needs them. */
    private function lifecycle(): MediaLifecycleService
    {
        return $this->lifecycle ??= ($this->resolveLifecycle)();
    }
}
