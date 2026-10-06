<?php

declare(strict_types=1);

namespace Nvl\Media\Traits;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\MediaAdder;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Slots\MediaSlot;

/**
 * @deprecated Use InteractsWithNvlMedia; this forwarding surface remains through major 5.
 *
 * @api
 *
 * @nvl-consumer-relation media
 * @nvl-consumer-relation mediaAssociations
 */
trait InteractsWithMedia
{
    use InteractsWithNvlMedia;

    /** Populate NVL definitions from the retained registration hooks. */
    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        $this->registerMediaSlots();
        $this->registerMediaConversions($asset);
    }

    /** Register legacy slots when the owner overrides this hook. */
    public function registerMediaSlots(): void {}

    /** Register legacy conversions when the owner overrides this hook. */
    public function registerMediaConversions(?Media $media = null): void {}

    /**
     * Retain the explicitly callable old hook; Laravel boots the nested namespaced trait.
     *
     * @internal
     */
    public static function bootInteractsWithMedia(): void {}

    /** Forward the retained deletePreservingMedia operation to the NVL owner API. */
    public function deletePreservingMedia(): ?bool
    {
        return $this->nvlMedia()->deleteOwnerPreservingMedia();
    }

    /**
     * @return MorphToMany<Media, covariant Model>
     *
     * @internal
     */
    public function media(): MorphToMany
    {
        return $this->nvlMedia()->query();
    }

    /**
     * @return MorphMany<MediaAssociation, $this>
     *
     * @internal
     */
    public function mediaAssociations(): MorphMany
    {
        return $this->nvlMediaAssociations();
    }

    /** Forward the retained addMediaSlot operation to the NVL owner API. */
    public function addMediaSlot(string $name): MediaSlot
    {
        return $this->nvlMedia()->registration()->slot($name);
    }

    /** Forward the retained addMediaConversion operation to the NVL owner API. */
    public function addMediaConversion(string $name): ConversionDefinition
    {
        return $this->nvlMedia()->registration()->conversion($name);
    }

    /**
     * @return array<string, ConversionDefinition>
     */
    public function getModelConversions(): array
    {
        return $this->nvlMedia()->conversions();
    }

    /** Forward the retained getMediaSlot operation to the NVL owner API. */
    public function getMediaSlot(string $name = 'default'): ?MediaSlot
    {
        return $this->nvlMedia()->slot($name);
    }

    /**
     * @return Collection<string, MediaSlot>
     */
    public function getRegisteredMediaSlots(): Collection
    {
        return $this->nvlMedia()->slots();
    }

    /** Forward the retained addMedia operation to the NVL owner API. */
    public function addMedia(string|UploadedFile $file, ?string $slot = null): MediaAdder
    {
        return $this->nvlMedia()->add($file, $slot);
    }

    /** Forward the retained copyMedia operation to the NVL owner API. */
    public function copyMedia(string|UploadedFile $file): MediaAdder
    {
        return $this->nvlMedia()->copy($file);
    }

    /** Forward the retained addMediaFromRequest operation to the NVL owner API. */
    public function addMediaFromRequest(string $key): MediaAdder
    {
        return $this->nvlMedia()->fromRequest($key);
    }

    /** Forward the retained addMediaFromUrl operation to the NVL owner API. */
    public function addMediaFromUrl(string $url, string ...$allowedMimeTypes): MediaAdder
    {
        return $this->nvlMedia()->fromUrl($url, ...$allowedMimeTypes);
    }

    /** Forward the retained addMediaFromBase64 operation to the NVL owner API. */
    public function addMediaFromBase64(string $base64data, string ...$allowedMimeTypes): MediaAdder
    {
        return $this->nvlMedia()->fromBase64($base64data, ...$allowedMimeTypes);
    }

    /** Forward the retained addMediaFromString operation to the NVL owner API. */
    public function addMediaFromString(string $text): MediaAdder
    {
        return $this->nvlMedia()->fromString($text);
    }

    /**
     * Create an upload builder from generated binary content.
     */
    public function addMediaFromBinary(
        string $contents,
        string $filename,
        string ...$allowedMimeTypes,
    ): MediaAdder {
        return $this->nvlMedia()->fromBinary($contents, $filename, ...$allowedMimeTypes);
    }

    /** Forward the retained addMediaFromDisk operation to the NVL owner API. */
    public function addMediaFromDisk(string $key, ?string $disk = null): MediaAdder
    {
        return $this->nvlMedia()->fromDisk($key, $disk);
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
        return $this->nvlMedia()->reuse($media, $collection, $locale, $order, $metadata, $dispatchVariations);
    }

    /**
     * @param  array<string, mixed>|callable  $filters
     * @return Collection<int, Media>
     */
    public function getMedia(string $collection = 'default', array|callable $filters = []): Collection
    {
        return $this->nvlMedia()->all($collection, $filters);
    }

    /**
     * @param  array<string, mixed>|callable  $filters
     */
    public function getFirstMedia(string $collection = 'default', array|callable $filters = []): ?Media
    {
        return $this->nvlMedia()->first($collection, $filters);
    }

    /**
     * @param  array<string, mixed>|callable  $filters
     */
    public function getLastMedia(string $collection = 'default', array|callable $filters = []): ?Media
    {
        return $this->nvlMedia()->last($collection, $filters);
    }

    /**
     * @param  array<string, mixed>|callable  $filters
     */
    public function hasMedia(string $collection = 'default', array|callable $filters = []): bool
    {
        return $this->nvlMedia()->has($collection, $filters);
    }

    /** Forward the retained getFirstMediaUrl operation to the NVL owner API. */
    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        return $this->nvlMedia()->firstUrl($collection, $conversion);
    }

    /** Forward the retained getLastMediaUrl operation to the NVL owner API. */
    public function getLastMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        return $this->nvlMedia()->lastUrl($collection, $conversion);
    }

    /** Forward the retained getFirstMediaPath operation to the NVL owner API. */
    public function getFirstMediaPath(string $collection = 'default', string $conversion = ''): string
    {
        return $this->nvlMedia()->firstPath($collection, $conversion);
    }

    /** Forward the retained getLastMediaPath operation to the NVL owner API. */
    public function getLastMediaPath(string $collection = 'default', string $conversion = ''): string
    {
        return $this->nvlMedia()->lastPath($collection, $conversion);
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
        return $this->nvlMedia()->buildUrl($collection, $parameters, $expiration);
    }

    /**
     * Build a centralized public URL for the first media item in a collection.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildPublicUrl(string $collection = 'default', array $parameters = []): string
    {
        return $this->nvlMedia()->buildPublicUrl($collection, $parameters);
    }

    /** Forward the retained getFirstTemporaryUrl operation to the NVL owner API. */
    public function getFirstTemporaryUrl(
        DateTimeInterface $expiration,
        string $collection = 'default',
        string $conversion = '',
    ): string {
        return $this->nvlMedia()->firstTemporaryUrl($expiration, $collection, $conversion);
    }

    /** Forward the retained getFallbackMediaUrl operation to the NVL owner API. */
    public function getFallbackMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        return $this->nvlMedia()->fallbackUrl($collection, $conversion);
    }

    /** Forward the retained getFallbackMediaPath operation to the NVL owner API. */
    public function getFallbackMediaPath(string $collection = 'default', string $conversion = ''): string
    {
        return $this->nvlMedia()->fallbackPath($collection, $conversion);
    }

    /** Forward the retained clearMediaCollection operation to the NVL owner API. */
    public function clearMediaCollection(string $collection = 'default'): static
    {
        $this->nvlMedia()->clear($collection);

        return $this;
    }

    /**
     * @param  array<int, Media|string>|Collection<int, Media|string>  $except
     */
    public function clearMediaCollectionExcept(string $collection = 'default', array|Collection $except = []): static
    {
        $this->nvlMedia()->clearExcept($collection, $except);

        return $this;
    }

    /** Forward the retained deleteMedia operation to the NVL owner API. */
    public function deleteMedia(string|Media $media): void
    {
        $this->nvlMedia()->delete($media);
    }

    /** Forward the retained deleteAllMedia operation to the NVL owner API. */
    public function deleteAllMedia(): static
    {
        $this->nvlMedia()->deleteAll();

        return $this;
    }

    /** Forward the retained detachMedia operation to the NVL owner API. */
    public function detachMedia(string|Media $media, ?string $collection = null): void
    {
        $this->nvlMedia()->detach($media, $collection);
    }

    /**
     * Batch-update media ordering for a collection using a single query.
     *
     * @param  array<int, string>  $ordered_ids  Media UUIDs in desired order (index = order value)
     */
    public function updateMediaOrder(array $ordered_ids, string $collection = 'default'): void
    {
        $this->nvlMedia()->order($ordered_ids, $collection);
    }

    /** Forward the retained getMediaModel operation to the NVL owner API. */
    public function getMediaModel(): string
    {
        return $this->nvlMedia()->modelClass();
    }
}
