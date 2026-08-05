<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Nvl\Media\Data\Display\MediaImageVariationPayload;
use Nvl\Media\Data\Display\MediaUsage;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Support\MediaImageConfiguration;
use Throwable;

/**
 * MediaResource: JSON API representation of a media record with variations and translations.
 *
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $originalUrl = $this->safeUrl();
        $previewUrl = $this->safePreviewUrl($originalUrl);

        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'extension' => $this->extension,
            'mimeType' => $this->mime_type,
            'size' => $this->size,
            'humanReadableSize' => $this->humanReadableSize(),
            'disk' => $this->disk,
            'folder' => $this->folder,
            'isPublic' => $this->is_public,
            'type' => $this->type,
            'digest' => $this->digest,
            'tags' => $this->tags ?? [],
            'metadata' => $this->metadata,
            'uploadedBy' => $this->uploaded_by,
            'url' => $originalUrl,
            'previewUrl' => $previewUrl,
            'imageVariations' => $this->whenLoaded('imageVariations', function () {
                return $this->imageVariations
                    ->map(fn (MediaImageVariation $v) => MediaImageVariationPayload::fromModel($v))
                    ->values();
            }),
            'translations' => $this->whenLoaded('translations', function (): array {
                return $this->translations
                    ->mapWithKeys(fn (MediaTranslation $t): array => [
                        $t->locale => [
                            'title' => $t->title,
                            'alt' => $t->alt,
                            'caption' => $t->caption,
                            'description' => $t->description,
                        ],
                    ])
                    ->all();
            }),
            'associationsCount' => $this->whenCounted('associations'),
            'usages' => $this->whenLoaded('associations', function (): array {
                return $this->associations
                    ->map(fn (MediaAssociation $association) => MediaUsage::fromModel($association))
                    ->values()
                    ->all();
            }),
            'fileExists' => $this->whenLoaded('associations', function (): bool {
                $media = $this->resource;

                return $media instanceof Media && $media->fileExistsOnDisk();
            }),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }

    private function safeUrl(): ?string
    {
        try {
            /** @var Media $media */
            $media = $this->resource;

            return $media->buildUrl();
        } catch (Throwable $e) {
            Log::warning("MediaResource URL build failed for [{$this->id}]: {$e->getMessage()}");

            return null;
        }
    }

    private function safePreviewUrl(?string $fallback = null): ?string
    {
        try {
            /** @var Media $media */
            $media = $this->resource;

            if ($media->relationLoaded('imageVariations')) {
                $presets = array_keys(MediaImageConfiguration::presets(enabledOnly: false));
                $preferred = ['thumb', 'optimized', ...$presets];

                foreach ($preferred as $label) {
                    if ($media->hasVariation($label)) {
                        return $media->buildUrl(['v' => $label]);
                    }
                }
            }

            return $fallback ?? $media->buildUrl();
        } catch (Throwable $e) {
            Log::warning("MediaResource preview URL build failed for [{$this->id}]: {$e->getMessage()}");

            return null;
        }
    }
}
