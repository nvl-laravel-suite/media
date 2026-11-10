<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Support\MediaImageConfiguration;
use Nvl\Support\Facades\PackageLog;
use Throwable;

/** Native Laravel resource for the privileged management projection used by the host fixture. */
final class MediaManagementData extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Media $media */
        $media = $this->resource;
        $url = self::safeUrl($media);

        return [
            'id' => $media->id,
            'filename' => $media->filename,
            'extension' => $media->extension,
            'mimeType' => $media->mime_type,
            'size' => $media->size,
            'humanReadableSize' => $media->humanReadableSize(),
            'disk' => $media->disk,
            'folder' => $media->folder,
            'isPublic' => $media->is_public,
            'type' => $media->type->value,
            'digest' => $media->digest,
            'tags' => array_values($media->tags ?? []),
            'metadata' => $media->metadata,
            'uploadedBy' => $media->uploaded_by,
            'url' => $url,
            'previewUrl' => self::safePreviewUrl($media, $url),
            'imageVariations' => $this->when(
                $media->relationLoaded('imageVariations'),
                fn (): array => array_values($media->imageVariations->map(
                    static fn (MediaImageVariation $variation): array => MediaImageVariationPayload::fromModel($variation)->toArray(),
                )->all()),
            ),
            'translations' => $this->when(
                $media->relationLoaded('translations'),
                fn (): array => $media->translations->mapWithKeys(static fn (MediaTranslation $translation): array => [
                    $translation->locale => [
                        'title' => $translation->title,
                        'alt' => $translation->alt,
                        'caption' => $translation->caption,
                        'description' => $translation->description,
                    ],
                ])->all(),
            ),
            'associationsCount' => $this->when(
                array_key_exists('associations_count', $media->getAttributes()),
                fn (): int => (int) $media->associations_count,
            ),
            'usages' => $this->when(
                $media->relationLoaded('associations'),
                fn (): array => array_values($media->associations->map(
                    static fn (MediaAssociation $association): array => MediaUsage::fromModel($association)->toArray(),
                )->all()),
            ),
            'fileExists' => $this->when(
                $media->relationLoaded('associations'),
                fn (): bool => $media->fileExistsOnDisk(),
            ),
            'createdAt' => $media->created_at?->format(DATE_ATOM),
            'updatedAt' => $media->updated_at?->format(DATE_ATOM),
        ];
    }

    private static function safeUrl(Media $media): ?string
    {
        try {
            return $media->buildUrl();
        } catch (Throwable $exception) {
            PackageLog::log('media', 'warning', 'nvl.media.management_url.failed', ['media_id' => $media->id, 'exception' => $exception::class], 'normal');

            return null;
        }
    }

    private static function safePreviewUrl(Media $media, ?string $fallback): ?string
    {
        try {
            if ($media->relationLoaded('imageVariations')) {
                foreach (['thumb', 'optimized', ...array_keys(MediaImageConfiguration::presets(enabledOnly: false))] as $label) {
                    if ($media->hasVariation($label)) {
                        return $media->buildUrl(['v' => $label]);
                    }
                }
            }

            return $fallback ?? $media->buildUrl();
        } catch (Throwable $exception) {
            PackageLog::log('media', 'warning', 'nvl.media.management_preview.failed', ['media_id' => $media->id, 'exception' => $exception::class], 'normal');

            return null;
        }
    }
}
