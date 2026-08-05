<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Illuminate\Database\Eloquent\Model;
use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** PublicMedia: public-safe media projection for public HTTP payloads. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PublicMedia extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $id,

        /** @var MediaType */
        #[TypeScriptType(MediaType::class)]
        public readonly MediaType $type,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $collection,

        /** @var int|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?int $order,

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

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $mimeType,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $extension,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $url,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $previewUrl,

        /** @var PublicMediaImage|null */
        #[TypeScriptType(PublicMediaImage::class)]
        public readonly ?PublicMediaImage $image,

        /** @var PublicMediaFile|null */
        #[TypeScriptType(PublicMediaFile::class)]
        public readonly ?PublicMediaFile $file,
    ) {}

    /**
     * Create public media data from a media record when the file is public.
     */
    public static function fromMedia(
        Media $media,
        ?Model $owner = null,
        ?string $collection = null,
        ?string $locale = null,
    ): ?self {
        if (! $media->is_public || ! $media->isAvailable()) {
            return null;
        }

        return self::fromInternal(MediaPayload::fromMedia($media, $owner, $collection, $locale));
    }

    /**
     * Project the internal media DTO to its public-safe representation.
     */
    public static function fromInternal(MediaPayload $media): ?self
    {
        if (! $media->isPublic || ! $media->status->isUsable() || $media->originalUrl === '') {
            return null;
        }

        return new self(
            id: $media->id,
            type: $media->type,
            collection: $media->collectionName,
            order: $media->order,
            title: $media->title,
            alt: $media->alt,
            caption: $media->caption,
            description: $media->description,
            mimeType: $media->mimeType,
            extension: $media->extension,
            url: $media->originalUrl,
            previewUrl: $media->previewUrl !== '' ? $media->previewUrl : $media->originalUrl,
            image: self::publicImage($media),
            file: $media->image === null ? self::publicFile($media) : null,
        );
    }

    private static function publicImage(MediaPayload $media): ?PublicMediaImage
    {
        if ($media->image === null) {
            return null;
        }

        $sizes = self::publicImageSizes($media->image->sizes);
        $previewUrl = $media->previewUrl !== '' ? $media->previewUrl : $media->originalUrl;

        return new PublicMediaImage(
            width: $media->image->width,
            height: $media->image->height,
            aspectRatio: $media->image->aspectRatio,
            src: $previewUrl,
            previewUrl: $previewUrl,
            srcSet: self::srcSet($sizes),
            sizes: $sizes,
        );
    }

    /**
     * @param  array<int, MediaImageSize>  $sizes
     * @return array<int, PublicMediaImageSize>
     */
    private static function publicImageSizes(array $sizes): array
    {
        /** @var array<string, MediaImageSize> $candidates */
        $candidates = [];

        foreach ($sizes as $size) {
            if (! $size->isAvailable || $size->url === null || $size->url === '') {
                continue;
            }

            $key = $size->width !== null ? 'w:'.$size->width : 'label:'.$size->label;
            $existing = $candidates[$key] ?? null;

            if (! $existing instanceof MediaImageSize || self::sourceWeight($size) < self::sourceWeight($existing)) {
                $candidates[$key] = $size;
            }
        }

        uasort($candidates, function (MediaImageSize $left, MediaImageSize $right): int {
            $leftWidth = $left->width ?? PHP_INT_MAX;
            $rightWidth = $right->width ?? PHP_INT_MAX;

            if ($leftWidth !== $rightWidth) {
                return $leftWidth <=> $rightWidth;
            }

            return $left->label <=> $right->label;
        });

        return array_values(array_map(
            static fn (MediaImageSize $size): PublicMediaImageSize => new PublicMediaImageSize(
                label: $size->label,
                name: $size->name,
                source: $size->source === 'original' ? 'original' : 'variation',
                width: $size->width,
                height: $size->height,
                aspectRatio: $size->aspectRatio,
                url: (string) $size->url,
                format: $size->format,
                size: $size->size,
                isGenerated: $size->isGenerated,
            ),
            $candidates,
        ));
    }

    /**
     * @param  array<int, PublicMediaImageSize>  $sizes
     */
    private static function srcSet(array $sizes): ?string
    {
        $parts = [];

        foreach ($sizes as $size) {
            if ($size->width === null || $size->url === '') {
                continue;
            }

            $parts[] = $size->url.' '.$size->width.'w';
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private static function publicFile(MediaPayload $media): PublicMediaFile
    {
        return new PublicMediaFile(
            extension: $media->extension,
            mimeType: $media->mimeType,
            size: $media->size,
            humanReadableSize: $media->humanReadableSize,
            url: $media->originalUrl,
        );
    }

    private static function sourceWeight(MediaImageSize $size): int
    {
        return $size->size ?? PHP_INT_MAX;
    }
}
