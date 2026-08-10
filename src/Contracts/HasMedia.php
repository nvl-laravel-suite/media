<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\MediaAdder;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;

/** HasMedia: contract for models that own and manage media attachments and conversions. */
interface HasMedia
{
    /**
     * @return MorphToMany<Media, covariant Model>
     */
    public function media(): MorphToMany;

    public function registerMediaSlots(): void;

    public function registerMediaConversions(?Media $media = null): void;

    public function addMedia(string|UploadedFile $file, ?string $slot = null): MediaAdder;

    public function copyMedia(string|UploadedFile $file): MediaAdder;

    public function addMediaFromRequest(string $key): MediaAdder;

    public function addMediaFromUrl(string $url, string ...$allowedMimeTypes): MediaAdder;

    public function addMediaFromBase64(string $base64data, string ...$allowedMimeTypes): MediaAdder;

    public function addMediaFromString(string $text): MediaAdder;

    /**
     * Create an upload builder from generated binary content.
     */
    public function addMediaFromBinary(
        string $contents,
        string $filename,
        string ...$allowedMimeTypes,
    ): MediaAdder;

    public function addMediaFromDisk(string $key, ?string $disk = null): MediaAdder;

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
    ): Media;

    /**
     * @param  array<string, mixed>|callable  $filters
     * @return Collection<int, Media>
     */
    public function getMedia(string $collection = 'default', array|callable $filters = []): Collection;

    /**
     * @param  array<string, mixed>|callable  $filters
     */
    public function getFirstMedia(string $collection = 'default', array|callable $filters = []): ?Media;

    /**
     * @param  array<string, mixed>|callable  $filters
     */
    public function getLastMedia(string $collection = 'default', array|callable $filters = []): ?Media;

    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string;

    public function getLastMediaUrl(string $collection = 'default', string $conversion = ''): string;

    public function getFirstMediaPath(string $collection = 'default', string $conversion = ''): string;

    public function getLastMediaPath(string $collection = 'default', string $conversion = ''): string;

    /**
     * Build a centralized URL for the first media item in a collection.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildUrl(string $collection = 'default', array $parameters = [], ?DateTimeInterface $expiration = null): string;

    /**
     * Build a centralized public URL for the first media item in a collection.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildPublicUrl(string $collection = 'default', array $parameters = []): string;

    public function getFirstTemporaryUrl(DateTimeInterface $expiration, string $collection = 'default', string $conversion = ''): string;

    public function getFallbackMediaUrl(string $collection = 'default', string $conversion = ''): string;

    public function getFallbackMediaPath(string $collection = 'default', string $conversion = ''): string;

    /**
     * @param  array<string, mixed>|callable  $filters
     */
    public function hasMedia(string $collection = 'default', array|callable $filters = []): bool;

    public function clearMediaCollection(string $collection = 'default'): static;

    /**
     * @param  array<int, Media|string>|Collection<int, Media|string>  $except
     */
    public function clearMediaCollectionExcept(string $collection = 'default', array|Collection $except = []): static;

    public function deleteMedia(string|Media $media): void;

    public function deleteAllMedia(): static;

    public function detachMedia(string|Media $media, ?string $collection = null): void;

    public function deletePreservingMedia(): ?bool;

    /**
     * @param  array<int, string>  $ordered_ids
     */
    public function updateMediaOrder(array $ordered_ids, string $collection = 'default'): void;

    public function getMediaSlot(string $name = 'default'): ?MediaSlot;

    /**
     * @return Collection<string, MediaSlot>
     */
    public function getRegisteredMediaSlots(): Collection;

    /**
     * @return array<string, ConversionDefinition>
     */
    public function getModelConversions(): array;

    public function getMediaModel(): string;

    public function addMediaSlot(string $name): MediaSlot;

    public function addMediaConversion(string $name): ConversionDefinition;
}
