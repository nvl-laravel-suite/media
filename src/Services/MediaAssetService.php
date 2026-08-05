<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Support\MediaAssetVersion;
use RuntimeException;

/** MediaAssetService resolves pre-existing image variations for centralized asset delivery. */
final class MediaAssetService
{
    public function __construct(
        private readonly MediaFileExistence $existence,
    ) {}

    /**
     * Resolve a media asset path and MIME type for output.
     *
     * This method only serves pre-existing variations. It does NOT generate
     * new variations on-the-fly. Variations are created at upload time or
     * via explicit regeneration triggers.
     *
     * @return array{disk: string, path: string, mime_type: string, etag: string}
     */
    public function resolve(
        Media $media,
        ?string $variationLabel = null,
    ): array {
        if (! $media->isAvailable()) {
            throw new RuntimeException("Media [{$media->id}] is not available for delivery.");
        }

        $media->loadMissing('imageVariations');

        // Look up a named variation (e.g. ?v=thumb)
        $variation = $this->resolveNamedVariation($media, $variationLabel);

        if (is_string($variationLabel) && trim($variationLabel) !== '') {
            if (! $variation instanceof MediaImageVariation) {
                throw new RuntimeException("Media variation [{$variationLabel}] was not found for media [{$media->id}].");
            }

            $path = $variation->getPath();
            if (! $this->existence->exists($media->disk, $path)) {
                throw new RuntimeException("Media variation file [{$path}] was not found on disk [{$media->disk}].");
            }

            return [
                'disk' => $media->disk,
                'path' => $path,
                'mime_type' => $variation->getMimeType(),
                'etag' => MediaAssetVersion::etag($media, $variation),
            ];
        }

        $path = $media->buildPath();

        if (! $this->existence->exists($media->disk, $path)) {
            throw new RuntimeException("Media file [{$path}] was not found on disk [{$media->disk}].");
        }

        return [
            'disk' => $media->disk,
            'path' => $path,
            'mime_type' => $media->mime_type,
            'etag' => MediaAssetVersion::etag($media),
        ];
    }

    private function resolveNamedVariation(Media $media, ?string $variationLabel): ?MediaImageVariation
    {
        if (! is_string($variationLabel) || trim($variationLabel) === '') {
            return null;
        }

        /** @var MediaImageVariation|null */
        return $media->imageVariations
            ->first(static fn (MediaImageVariation $variation): bool => $variation->label === $variationLabel
                && $variation->source_revision === $media->revision
                && $variation->status === MediaLifecycleStatus::Available->value);
    }
}
