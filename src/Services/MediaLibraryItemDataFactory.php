<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use InvalidArgumentException;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Throwable;

/** MediaLibraryItemDataFactory builds the Inertia media library index payload from Media models. */
final readonly class MediaLibraryItemDataFactory
{
    public function __construct(
        private MediaConfiguredVariationService $configuredVariationService,
        private MediaLocaleResolver $localeResolver,
        private MediaUrlResolver $urlResolver,
    ) {}

    /**
     * Build a library item DTO from a Media model.
     */
    public function fromModel(Media $media): MediaLibraryItem
    {
        return $this->build(
            $media,
            $media->associations->first()?->collection,
        );
    }

    /**
     * Build a library item using one explicitly selected owner association.
     */
    public function fromAssociation(
        Media $media,
        MediaAssociation $association,
    ): MediaLibraryItem {
        if (! $association->exists) {
            throw new InvalidArgumentException(
                'The selected Media association must be persisted.',
            );
        }

        if ((string) $association->media_id !== (string) $media->id) {
            throw new InvalidArgumentException(
                'The selected Media association does not belong to the supplied Media record.',
            );
        }

        return $this->build($media, $association->collection);
    }

    /**
     * Build the stable library projection with an explicit collection.
     */
    private function build(Media $media, ?string $collection): MediaLibraryItem
    {
        $safeUrl = $this->safeUrl($media);

        return new MediaLibraryItem(
            id: (string) $media->id,
            filename: $media->filename,
            title: $this->resolveDisplayTitle($media),
            extension: $media->extension,
            mimeType: $media->mime_type,
            size: (int) $media->size,
            humanReadableSize: $media->humanReadableSize(),
            disk: $media->disk,
            folder: $media->folder,
            collection: $collection,
            isPublic: (bool) $media->is_public,
            type: $media->type->value,
            tags: is_array($media->tags) ? $media->tags : [],
            associationsCount: (int) ($media->associations_count ?? 0),
            createdAt: $media->created_at?->toISOString(),
            updatedAt: $media->updated_at?->toISOString(),
            previewUrl: $this->resolvePreviewUrl($media, $safeUrl),
            url: $safeUrl,
        );
    }

    private function safeUrl(Media $media): ?string
    {
        try {
            return $this->urlResolver->buildUrl($media);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolvePreviewUrl(Media $media, ?string $fallback): ?string
    {
        if (! $media->type->supportsConversions()) {
            return $fallback;
        }

        $previewVariation = $this->configuredVariationService->preferredPreviewVariationLabel();

        if ($previewVariation === null) {
            return $fallback;
        }

        if (! $media->relationLoaded('imageVariations') || ! $media->hasVariation($previewVariation)) {
            return null;
        }

        try {
            return $this->urlResolver->buildUrl($media, ['v' => $previewVariation]);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveDisplayTitle(Media $media): ?string
    {
        $title = $media->translated('title', $this->localeResolver->resolve());

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
