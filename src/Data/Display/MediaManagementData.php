<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Support\MediaImageConfiguration;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/** Privileged management projection of a media record. */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MediaManagementData extends Data
{
    use DataTransform;

    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>|null  $metadata
     * @param  list<MediaImageVariationPayload>|Optional  $imageVariations
     * @param  array<string, array<string, string|null>>|Optional  $translations
     * @param  list<MediaUsage>|Optional  $usages
     */
    public function __construct(
        public readonly string $id,
        public readonly string $filename,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $humanReadableSize,
        public readonly string $disk,
        public readonly ?string $folder,
        public readonly bool $isPublic,
        public readonly MediaType $type,
        public readonly string $digest,
        public readonly array $tags,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?array $metadata,
        public readonly ?string $uploadedBy,
        public readonly ?string $url,
        public readonly ?string $previewUrl,
        public readonly array|Optional $imageVariations,
        public readonly array|Optional $translations,
        public readonly int|Optional $associationsCount,
        public readonly array|Optional $usages,
        public readonly bool|Optional $fileExists,
        public readonly ?Carbon $createdAt,
        public readonly ?Carbon $updatedAt,
    ) {}

    public static function fromModel(Media $media): self
    {
        $url = self::safeUrl($media);

        return new self(
            id: $media->id,
            filename: $media->filename,
            extension: $media->extension,
            mimeType: $media->mime_type,
            size: $media->size,
            humanReadableSize: $media->humanReadableSize(),
            disk: $media->disk,
            folder: $media->folder,
            isPublic: $media->is_public,
            type: $media->type,
            digest: $media->digest,
            tags: array_values($media->tags ?? []),
            metadata: $media->metadata,
            uploadedBy: $media->uploaded_by,
            url: $url,
            previewUrl: self::safePreviewUrl($media, $url),
            imageVariations: $media->relationLoaded('imageVariations')
                ? array_values($media->imageVariations->map(fn (MediaImageVariation $variation): MediaImageVariationPayload => MediaImageVariationPayload::fromModel($variation))->all())
                : Optional::create(),
            translations: $media->relationLoaded('translations')
                ? $media->translations->mapWithKeys(fn (MediaTranslation $translation): array => [
                    $translation->locale => [
                        'title' => $translation->title,
                        'alt' => $translation->alt,
                        'caption' => $translation->caption,
                        'description' => $translation->description,
                    ],
                ])->all()
                : Optional::create(),
            associationsCount: array_key_exists('associations_count', $media->getAttributes())
                ? (int) $media->associations_count
                : Optional::create(),
            usages: $media->relationLoaded('associations')
                ? array_values($media->associations->map(fn (MediaAssociation $association): MediaUsage => MediaUsage::fromModel($association))->all())
                : Optional::create(),
            fileExists: $media->relationLoaded('associations') ? $media->fileExistsOnDisk() : Optional::create(),
            createdAt: $media->created_at,
            updatedAt: $media->updated_at,
        );
    }

    private static function safeUrl(Media $media): ?string
    {
        try {
            return $media->buildUrl();
        } catch (Throwable $exception) {
            Log::warning("Media management URL build failed for [{$media->id}]: {$exception->getMessage()}");

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
            Log::warning("Media management preview URL build failed for [{$media->id}]: {$exception->getMessage()}");

            return null;
        }
    }
}
