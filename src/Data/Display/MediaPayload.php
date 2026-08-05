<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Support\MediaImageConfiguration;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;
use Throwable;

/** MediaPayload: privileged owner-aware DTO containing storage and association details. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MediaPayload extends Data
{
    use DataTransform;

    private const SIZE_SOURCE_ORIGINAL = 'original';

    private const SIZE_SOURCE_VARIATION = 'variation';

    private const SIZE_SOURCE_CONFIGURED = 'configured';

    public function __construct(
        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $id,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $uuid,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $modelType,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $modelId,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $collectionName,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $locale,

        /** @var int|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?int $order,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $name,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $fileName,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $mimeType,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $disk,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $folder,

        /** @var bool */
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isPublic,

        /** @var int */
        #[LiteralTypeScriptType('number')]
        public readonly int $size,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $humanReadableSize,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $extension,

        /** @var MediaType */
        #[TypeScriptType(MediaType::class)]
        public readonly MediaType $type,

        /** @var MediaLifecycleStatus */
        #[TypeScriptType(MediaLifecycleStatus::class)]
        public readonly MediaLifecycleStatus $status,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $originalUrl,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $previewUrl,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $title,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $alt,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $caption,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $description,

        /** @var array<string, mixed>|null */
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?array $metadata,

        /** @var array<string, mixed>|null */
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?array $associationMetadata,

        /** @var MediaImage|null */
        #[TypeScriptType(MediaImage::class)]
        public readonly ?MediaImage $image,

        /** @var MediaDocument|null */
        #[TypeScriptType(MediaDocument::class)]
        public readonly ?MediaDocument $document,

        /** @var MediaFile|null */
        #[TypeScriptType(MediaFile::class)]
        public readonly ?MediaFile $file,

        /** @var Carbon|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?Carbon $createdAt,

        /** @var Carbon|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?Carbon $updatedAt,
    ) {}

    public static function fromMedia(
        Media $media,
        ?Model $owner = null,
        ?string $collection = null,
        ?string $locale = null,
    ): self {
        return self::fromModel($media, $owner, $collection, $locale);
    }

    public static function fromModel(
        Media $media,
        ?Model $owner = null,
        ?string $collection = null,
        ?string $locale = null,
    ): self {
        $media->loadMissing(['associations', 'translations', 'imageVariations']);

        $association = self::resolveAssociation($media, $owner, $collection);
        $contentLocale = $locale ?? $media->getCurrentLocale();
        $metadata = self::normalizeMetadata($media->metadata);
        $associationMetadata = self::normalizeMetadata($association?->metadata);
        $type = $media->type;
        $originalUrl = self::safeOriginalUrl($media);
        $previewUrl = self::safePreviewUrl($media, $originalUrl);
        [$width, $height] = self::resolveDimensions($metadata);
        $humanReadableSize = $media->humanReadableSize();

        return new self(
            id: $media->id,
            uuid: $media->id,
            modelType: $association?->associable_type,
            modelId: $association?->associable_id,
            collectionName: $association?->collection,
            locale: $association?->locale,
            order: $association?->order,
            name: pathinfo($media->filename, PATHINFO_FILENAME),
            fileName: $media->filename,
            mimeType: $media->mime_type,
            disk: $media->disk,
            folder: $media->folder,
            isPublic: $media->is_public,
            size: $media->size,
            humanReadableSize: $humanReadableSize,
            extension: $media->extension,
            type: $type,
            status: $media->status,
            originalUrl: $originalUrl,
            previewUrl: $previewUrl,
            title: self::firstString(
                $associationMetadata['title'] ?? null,
                $media->translated('title', $contentLocale),
                $metadata['title'] ?? null,
            ),
            alt: self::firstString(
                $associationMetadata['alt'] ?? null,
                $media->translated('alt', $contentLocale),
                $metadata['alt'] ?? null,
                $metadata['alt_text'] ?? null,
            ),
            caption: self::firstString(
                $associationMetadata['caption'] ?? null,
                $media->translated('caption', $contentLocale),
                $metadata['caption'] ?? null,
            ),
            description: self::firstString(
                $associationMetadata['description'] ?? null,
                $media->translated('description', $contentLocale),
                $metadata['description'] ?? null,
            ),
            metadata: $metadata,
            associationMetadata: $associationMetadata,
            image: $type === MediaType::IMAGE ? self::imagePayload($media, $width, $height, $originalUrl, $previewUrl) : null,
            document: $type === MediaType::DOCUMENT ? self::documentPayload($media, $humanReadableSize, $originalUrl) : null,
            file: ! in_array($type, [MediaType::IMAGE, MediaType::DOCUMENT], true) ? self::filePayload($media, $humanReadableSize, $originalUrl) : null,
            createdAt: $media->created_at,
            updatedAt: $media->updated_at,
        );
    }

    private static function resolveAssociation(Media $media, ?Model $owner, ?string $collection): ?MediaAssociation
    {
        if ($owner instanceof Model) {
            $ownerType = $owner->getMorphClass();
            $ownerKey = $owner->getKey();

            if (! is_string($ownerKey) && ! is_int($ownerKey)) {
                return null;
            }

            $ownerId = (string) $ownerKey;

            /** @var MediaAssociation|null $association */
            $association = $media->associations->first(
                fn (MediaAssociation $candidate): bool => $candidate->associable_type === $ownerType
                    && (string) $candidate->associable_id === $ownerId
                    && ($collection === null || $candidate->collection === $collection)
            );

            if ($association instanceof MediaAssociation) {
                return $association;
            }
        }

        if ($collection !== null) {
            /** @var MediaAssociation|null $association */
            $association = $media->associations->first(
                fn (MediaAssociation $candidate): bool => $candidate->collection === $collection
            );

            if ($association instanceof MediaAssociation) {
                return $association;
            }
        }

        /** @var MediaAssociation|null */
        return $media->associations->first();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private static function normalizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null || $metadata === []) {
            return null;
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{0: int|null, 1: int|null}
     */
    private static function resolveDimensions(?array $metadata): array
    {
        return [
            self::metadataInt($metadata, ['original_width', 'width']),
            self::metadataInt($metadata, ['original_height', 'height']),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<int, string>  $keys
     */
    private static function metadataInt(?array $metadata, array $keys): ?int
    {
        if ($metadata === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;

            if (is_int($value)) {
                return $value > 0 ? $value : null;
            }

            if (is_numeric($value)) {
                $intValue = (int) $value;

                return $intValue > 0 ? $intValue : null;
            }
        }

        return null;
    }

    private static function imagePayload(
        Media $media,
        ?int $width,
        ?int $height,
        string $originalUrl,
        string $previewUrl,
    ): MediaImage {
        $breakpoints = self::imageBreakpoints();

        return new MediaImage(
            width: $width,
            height: $height,
            aspectRatio: self::aspectRatio($width, $height),
            originalUrl: $originalUrl,
            previewUrl: $previewUrl,
            breakpoints: $breakpoints,
            sizes: self::imageSizes($media, $width, $height, $originalUrl, $breakpoints),
            variations: $media->imageVariations
                ->map(function (MediaImageVariation $variation) use ($media): MediaImageVariationPayload {
                    $variation->setRelation('media', $media);

                    return MediaImageVariationPayload::fromModel($variation);
                })
                ->values()
                ->all(),
        );
    }

    /**
     * @return array<int, MediaImageBreakpoint>
     */
    private static function imageBreakpoints(): array
    {
        $presets = MediaImageConfiguration::presets(enabledOnly: false);

        $breakpoints = [];

        foreach ($presets as $label => $preset) {
            if ($label === '') {
                continue;
            }

            $breakpoints[] = new MediaImageBreakpoint(
                label: $label,
                width: self::positiveInt($preset['width'] ?? $preset['max_size'] ?? null),
                height: self::positiveInt($preset['height'] ?? $preset['max_size'] ?? null),
                format: self::stringOrNull($preset['format'] ?? null),
                quality: self::intOrNull($preset['quality'] ?? null),
                enabled: (bool) ($preset['enabled'] ?? true),
            );
        }

        return $breakpoints;
    }

    /**
     * @param  array<int, MediaImageBreakpoint>  $breakpoints
     * @return array<int, MediaImageSize>
     */
    private static function imageSizes(
        Media $media,
        ?int $width,
        ?int $height,
        string $originalUrl,
        array $breakpoints,
    ): array {
        $sizes = [
            new MediaImageSize(
                label: 'original',
                name: 'original',
                source: self::SIZE_SOURCE_ORIGINAL,
                width: $width,
                height: $height,
                aspectRatio: self::aspectRatio($width, $height),
                url: $originalUrl,
                format: $media->extension,
                quality: null,
                size: $media->size,
                isGenerated: false,
                isAvailable: $originalUrl !== '',
            ),
        ];

        $variations = $media->imageVariations
            ->sort(function (MediaImageVariation $left, MediaImageVariation $right): int {
                $leftWidth = $left->width ?? PHP_INT_MAX;
                $rightWidth = $right->width ?? PHP_INT_MAX;

                if ($leftWidth !== $rightWidth) {
                    return $leftWidth <=> $rightWidth;
                }

                $leftHeight = $left->height ?? PHP_INT_MAX;
                $rightHeight = $right->height ?? PHP_INT_MAX;

                if ($leftHeight !== $rightHeight) {
                    return $leftHeight <=> $rightHeight;
                }

                return $left->label <=> $right->label;
            })
            ->values();

        foreach ($variations as $variation) {
            $sizes[] = self::imageSizeFromVariation($media, $variation);
        }

        foreach ($breakpoints as $breakpoint) {
            if (! $breakpoint->enabled || self::breakpointHasVariation($breakpoint, $variations->all())) {
                continue;
            }

            $sizes[] = self::imageSizeFromBreakpoint($breakpoint);
        }

        return $sizes;
    }

    private static function imageSizeFromBreakpoint(MediaImageBreakpoint $breakpoint): MediaImageSize
    {
        return new MediaImageSize(
            label: $breakpoint->label,
            name: self::semanticSizeName($breakpoint->label, $breakpoint->width, true),
            source: self::SIZE_SOURCE_CONFIGURED,
            width: $breakpoint->width,
            height: $breakpoint->height,
            aspectRatio: self::aspectRatio($breakpoint->width, $breakpoint->height),
            url: null,
            format: $breakpoint->format,
            quality: $breakpoint->quality,
            size: null,
            isGenerated: false,
            isAvailable: false,
        );
    }

    private static function imageSizeFromVariation(Media $media, MediaImageVariation $variation): MediaImageSize
    {
        $url = self::safeVariationUrl($media, $variation->label);

        return new MediaImageSize(
            label: $variation->label,
            name: self::semanticSizeName($variation->label, $variation->width),
            source: self::SIZE_SOURCE_VARIATION,
            width: $variation->width,
            height: $variation->height,
            aspectRatio: self::aspectRatio($variation->width, $variation->height),
            url: $url,
            format: $variation->format,
            quality: $variation->quality,
            size: $variation->size,
            isGenerated: true,
            isAvailable: $url !== null && $url !== '',
        );
    }

    /**
     * @param  array<int, MediaImageVariation>  $variations
     */
    private static function breakpointHasVariation(MediaImageBreakpoint $breakpoint, array $variations): bool
    {
        foreach ($variations as $variation) {
            $sameLabelAndSize = $variation->label === $breakpoint->label
                && $variation->width === $breakpoint->width
                && $variation->height === $breakpoint->height;
            $sameDimensions = $breakpoint->width !== null
                && $breakpoint->height !== null
                && $variation->width === $breakpoint->width
                && $variation->height === $breakpoint->height;

            if ($sameLabelAndSize || $sameDimensions) {
                return true;
            }
        }

        return false;
    }

    private static function semanticSizeName(string $label, ?int $width, bool $configured = false): string
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', trim($label)));
        $normalized = trim($normalized, '-');

        if ($normalized === '' && $width === null) {
            return $configured ? 'configured' : 'custom';
        }

        if (in_array($normalized, ['original', 'source'], true)) {
            return 'original';
        }

        if ($normalized === 'preview') {
            return 'preview';
        }

        if (in_array($normalized, ['small', 'medium', 'large', 'hero'], true)) {
            return $normalized;
        }

        if (in_array($normalized, ['thumb', 'thumbnail'], true) && ($width === null || $width <= 200)) {
            return 'thumbnail';
        }

        return self::semanticSizeNameFromWidth($width) ?? $normalized;
    }

    private static function semanticSizeNameFromWidth(?int $width): ?string
    {
        if ($width === null || $width <= 0) {
            return null;
        }

        if ($width <= 200) {
            return 'thumbnail';
        }

        if ($width <= 420) {
            return 'small';
        }

        if ($width <= 900) {
            return 'medium';
        }

        if ($width <= 1440) {
            return 'large';
        }

        return 'extra-large';
    }

    private static function aspectRatio(?int $width, ?int $height): ?float
    {
        return ($width !== null && $height !== null && $height > 0) ? round($width / $height, 6) : null;
    }

    private static function documentPayload(Media $media, string $humanReadableSize, string $url): MediaDocument
    {
        return new MediaDocument(
            extension: $media->extension,
            mimeType: $media->mime_type,
            size: $media->size,
            humanReadableSize: $humanReadableSize,
            url: $url,
        );
    }

    private static function filePayload(Media $media, string $humanReadableSize, string $url): MediaFile
    {
        return new MediaFile(
            extension: $media->extension,
            mimeType: $media->mime_type,
            size: $media->size,
            humanReadableSize: $humanReadableSize,
            url: $url,
        );
    }

    private static function safeOriginalUrl(Media $media): string
    {
        try {
            return $media->buildUrl();
        } catch (Throwable) {
            return '';
        }
    }

    private static function safeVariationUrl(Media $media, string $label): ?string
    {
        try {
            $url = $media->buildUrl(['v' => $label]);

            return $url !== '' ? $url : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function safePreviewUrl(Media $media, string $fallback): string
    {
        if ($media->type !== MediaType::IMAGE) {
            return $fallback;
        }

        $presets = MediaImageConfiguration::presets(enabledOnly: false);
        $preferred = ['thumb', 'optimized', ...array_keys($presets)];

        foreach (array_unique($preferred) as $label) {
            if ($label === '' || ! $media->hasVariation($label)) {
                continue;
            }

            try {
                $url = $media->buildUrl(['v' => $label]);

                return $url !== '' ? $url : $fallback;
            } catch (Throwable) {
                return $fallback;
            }
        }

        return $fallback;
    }

    private static function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        $integer = self::intOrNull($value);

        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
