<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Enums\MediaDeliveryMode;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Ownership\MediaDeliveryGuard;
use Nvl\Media\Support\MediaAssetVersion;
use RuntimeException;

/**
 * Resolves canonical storage metadata for consumer-owned application delivery.
 *
 * Consumers authenticate and authorize requests before calling this adapter.
 * Returned paths are server-side metadata and must not be exposed in responses.
 *
 * @api
 */
final class MediaAssetService
{
    public function __construct(
        private readonly MediaFileExistence $existence,
        private readonly MediaDeliveryGuard $delivery,
        private readonly MediaDeliveryPolicy $policy,
    ) {}

    /**
     * Resolve an available original or pre-existing variation after request authorization.
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
        MediaDeliveryMode $mode = MediaDeliveryMode::Application,
    ): array {
        $this->delivery->assertAsset($media);
        if (! $media->isAvailable()) {
            throw new RuntimeException("Media [{$media->id}] is not available for delivery.");
        }

        if (! in_array($mode, [MediaDeliveryMode::Application, MediaDeliveryMode::SignedApplication], true)) {
            throw new RuntimeException('Asset resolution requires an application delivery mode.');
        }
        $canonical = $this->canonicalAsset($media);
        $this->policy->mode($media->disk, $media->visibility, $mode);

        $media->setRelation('imageVariations', $canonical->imageVariations()->chaperone('media')->useWritePdo()->get());

        // Look up a named variation (e.g. ?v=thumb)
        $variation = $this->resolveNamedVariation($media, $variationLabel);

        if (is_string($variationLabel) && trim($variationLabel) !== '') {
            if (! $variation instanceof MediaImageVariation) {
                throw new RuntimeException("Media variation [{$variationLabel}] was not found for media [{$media->id}].");
            }

            $this->delivery->assertVariation($variation, $media);
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

    /** Reject stale or forged storage facts before serving a carried asset. */
    private function canonicalAsset(Media $media): Media
    {
        $canonical = MediaModelRegistry::query()->useWritePdo()->find($media->id);
        if (! $canonical instanceof Media || ! $canonical->isAvailable()) {
            throw new RuntimeException("Media [{$media->id}] is not available for delivery.");
        }
        foreach (['disk', 'storage_path', 'hash', 'folder', 'extension', 'mime_type', 'revision', 'status', 'visibility', 'is_public'] as $field) {
            if ($media->getAttribute($field) !== $canonical->getAttribute($field)) {
                throw new RuntimeException('Asset delivery requires canonical persisted storage, visibility and revision facts.');
            }
        }

        return $canonical;
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
