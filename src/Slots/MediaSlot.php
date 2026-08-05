<?php

declare(strict_types=1);

namespace Nvl\Media\Slots;

use Closure;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Support\MediaDiskResolver;

/**
 * MediaSlot: defines constraints, disk, path, and conversion presets for a named media role on a model.
 *
 * A "slot" represents a structural role that media plays for a specific model instance
 * (e.g. avatar, certificate, featured image). It is NOT a folder or a virtual grouping —
 * it answers the question: "What is this media for on this model?"
 */
class MediaSlot
{
    public const string SHARING_SHARED = 'shared';

    public const string SHARING_EXCLUSIVE = 'exclusive';

    /** @var string Storage disk name */
    public string $disk;

    /** @var string Path template with token interpolation (e.g. 'users/{id}/avatar') */
    public string $pathTemplate;

    /** @var string Sharing mode: 'shared' enables dedup, 'exclusive' forces unique records */
    public string $sharingMode;

    /** @var int|null Maximum number of media items in this slot (null = unlimited) */
    public ?int $slotSizeLimit = null;

    /** @var array<int, string> Accepted MIME type strings for upload validation */
    public array $acceptedMimeTypes = [];

    /** @var Closure|null Custom file acceptance callback */
    public ?Closure $fileAcceptor = null;

    /** @var int|null Maximum file size in bytes */
    public ?int $maxFileSize = null;

    /** @var bool Whether files in this slot are publicly accessible */
    public bool $isPublic = false;

    /** @var bool Whether this slot holds exactly one file (replaces previous on upload) */
    public bool $isSingleFile = false;

    /** @var array<string, string> Fallback URLs keyed by conversion name ('' = default) */
    public array $fallbackUrls = [];

    /** @var array<string, string> Fallback paths keyed by conversion name ('' = default) */
    public array $fallbackPaths = [];

    /** @var array<int, string> Default tags applied to all uploads in this slot */
    public array $defaultTags = [];

    /** @var array<string, ConversionDefinition> Named conversion presets */
    public array $conversions = [];

    /** @var string|null Target format for original file optimization (e.g. 'webp') */
    public ?string $convertFormat = null;

    /** @var int|null Quality for original file optimization (0-100) */
    public ?int $convertQuality = null;

    /** @var int|null Maximum pixels for longest edge of original file */
    public ?int $convertMaxSize = null;

    /**
     * Create a new media slot configuration.
     *
     * @param  string  $name  Unique slot name (e.g. 'avatar', 'featured', 'documents')
     */
    public function __construct(
        public readonly string $name,
    ) {
        $this->disk = MediaDiskResolver::resolve();
        $this->pathTemplate = MediaConfiguration::string('media.default_path', 'misc');
        $this->sharingMode = self::SHARING_SHARED;
    }

    /**
     * Set the storage disk for files uploaded to this slot.
     *
     * @param  string  $disk  Filesystem disk name
     */
    public function useDisk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Set the path template for file storage location on disk.
     *
     * @param  string  $template  Path with optional tokens: {id}, {model_type}, {model_id}, {collection}, {year}, {month}, {day}
     */
    public function path(string $template): static
    {
        $this->pathTemplate = $template;

        return $this;
    }

    /**
     * Mark files in this slot as publicly accessible (no signed URLs needed).
     *
     * @param  bool  $public  Whether the slot is public
     */
    public function isPublic(bool $public = true): static
    {
        $this->isPublic = $public;

        return $this;
    }

    /**
     * Configure a globally reusable public asset.
     */
    public function publicReusable(): static
    {
        return $this->isPublic()->shared();
    }

    /**
     * Configure a private file that always receives its own media record.
     */
    public function privateExclusive(): static
    {
        return $this->isPublic(false)->exclusive();
    }

    /**
     * Configure a private one-to-one file that replaces its predecessor safely.
     */
    public function oneToOne(): static
    {
        return $this->privateExclusive()->singleFile();
    }

    /**
     * Enable media record sharing across multiple owners via digest deduplication.
     */
    public function shared(): static
    {
        $this->sharingMode = self::SHARING_SHARED;

        return $this;
    }

    /**
     * Disable digest deduplication — each upload creates a unique media record.
     */
    public function exclusive(): static
    {
        $this->sharingMode = self::SHARING_EXCLUSIVE;

        return $this;
    }

    /**
     * Restrict the slot to hold exactly one file, replacing the previous on upload.
     */
    public function singleFile(): static
    {
        $this->isSingleFile = true;
        $this->slotSizeLimit = 1;

        return $this;
    }

    /**
     * Cap the number of media items, removing oldest when exceeded.
     *
     * @param  int  $max  Maximum number of items to keep
     */
    public function onlyKeepLatest(int $max): static
    {
        $this->slotSizeLimit = max(1, $max);

        return $this;
    }

    /**
     * Restrict which MIME types can be uploaded to this slot.
     *
     * Accepts MimeType enums, raw MIME strings, or a mix of both.
     *
     * @param  array<int, MimeType|string>  $mimeTypes  Allowed MIME types
     */
    public function acceptsMimeTypes(array $mimeTypes): static
    {
        $this->acceptedMimeTypes = array_map(
            static fn (MimeType|string $type): string => $type instanceof MimeType ? $type->value : $type,
            $mimeTypes,
        );

        return $this;
    }

    /**
     * Register a custom file acceptance callback for additional validation.
     *
     * @param  Closure  $callback  Receives UploadedFile, returns bool
     */
    public function acceptsFile(Closure $callback): static
    {
        $this->fileAcceptor = $callback;

        return $this;
    }

    /**
     * Set the maximum file size for uploads to this slot.
     *
     * @param  int  $bytes  Maximum file size in bytes
     */
    public function maxFileSize(int $bytes): static
    {
        $this->maxFileSize = $bytes;

        return $this;
    }

    /**
     * Register a fallback URL returned when the slot is empty.
     *
     * @param  string  $url  Fallback URL
     * @param  string  $conversion  Conversion name ('' for default/original)
     */
    public function useFallbackUrl(string $url, string $conversion = ''): static
    {
        $this->fallbackUrls[$conversion] = $url;

        return $this;
    }

    /**
     * Register a fallback path returned when the slot is empty.
     *
     * @param  string  $path  Fallback file path
     * @param  string  $conversion  Conversion name ('' for default/original)
     */
    public function useFallbackPath(string $path, string $conversion = ''): static
    {
        $this->fallbackPaths[$conversion] = $path;

        return $this;
    }

    /**
     * Register multiple conversion presets from definitions or shorthand config arrays.
     *
     * @param  array<string, ConversionDefinition|array<int|string, mixed>>  $conversions  Named conversion configs
     */
    public function withConversions(array $conversions): static
    {
        foreach ($conversions as $name => $dimensions) {
            if ($dimensions instanceof ConversionDefinition) {
                $this->conversions[$name] = $dimensions;

                continue;
            }

            $definition = new ConversionDefinition($name);
            $parsed = $this->parseConversionConfig($dimensions);

            if ($parsed['width'] !== null) {
                $definition->width($parsed['width']);
            }

            if ($parsed['height'] !== null) {
                $definition->height($parsed['height']);
            }

            if ($parsed['quality'] !== null) {
                $definition->quality($parsed['quality']);
            }

            if ($parsed['format'] !== null) {
                $definition->format($parsed['format']);
            }

            if ($parsed['fit'] !== null && $parsed['width'] !== null && $parsed['height'] !== null) {
                $definition->fit($parsed['fit'], $parsed['width'], $parsed['height']);
            }

            $this->conversions[$name] = $definition;
        }

        return $this;
    }

    /**
     * Register a single conversion preset via a callback.
     *
     * @param  string  $name  Conversion name
     * @param  callable  $config  Callback receiving a ConversionDefinition to configure
     */
    public function addConversion(string $name, callable $config): static
    {
        $definition = new ConversionDefinition($name);
        $config($definition);
        $this->conversions[$name] = $definition;

        return $this;
    }

    /**
     * Set default tags applied to all uploads in this slot.
     *
     * @param  array<int, string>  $tags  Tag names
     */
    public function withTags(array $tags): static
    {
        $this->defaultTags = $tags;

        return $this;
    }

    /**
     * Get the fallback URL for a conversion (or the default).
     *
     * @param  string  $conversion  Conversion name ('' for default/original)
     * @return string Fallback URL or empty string
     */
    public function getFallbackUrl(string $conversion = ''): string
    {
        return $this->fallbackUrls[$conversion] ?? $this->fallbackUrls[''] ?? '';
    }

    /**
     * Get the fallback path for a conversion (or the default).
     *
     * @param  string  $conversion  Conversion name ('' for default/original)
     * @return string Fallback path or empty string
     */
    public function getFallbackPath(string $conversion = ''): string
    {
        return $this->fallbackPaths[$conversion] ?? $this->fallbackPaths[''] ?? '';
    }

    /**
     * Check if uploads to this slot may reuse existing media records via digest dedup.
     */
    public function isShared(): bool
    {
        return $this->sharingMode === self::SHARING_SHARED;
    }

    /**
     * Check if uploads to this slot must create unique media records.
     */
    public function isExclusive(): bool
    {
        return $this->sharingMode === self::SHARING_EXCLUSIVE;
    }

    /**
     * Check whether the slot represents reusable public assets.
     */
    public function isReusable(): bool
    {
        return $this->isPublic && $this->isShared();
    }

    /**
     * Check whether files in the slot require protected delivery.
     */
    public function isPrivate(): bool
    {
        return ! $this->isPublic;
    }

    // ── Original file optimization ──────────────────────────────────

    /**
     * Convert the original file to the given format before storing.
     *
     * @param  MimeType|string  $format  Target format (e.g. MimeType::Webp or 'webp')
     */
    public function convertTo(MimeType|string $format): static
    {
        $this->convertFormat = $format instanceof MimeType ? $format->extension() : $format;

        return $this;
    }

    /**
     * Set the quality for original file optimization.
     *
     * @param  int  $quality  Quality level 0-100
     */
    public function withQuality(int $quality): static
    {
        $this->convertQuality = max(0, min(100, $quality));

        return $this;
    }

    /**
     * Cap the longest edge of the original image, preserving aspect ratio.
     *
     * @param  int  $pixels  Maximum pixels for the longest edge
     */
    public function maxSize(int $pixels): static
    {
        $this->convertMaxSize = max(1, $pixels);

        return $this;
    }

    /**
     * Whether this slot requires original file optimization before storage.
     */
    public function shouldConvertOriginal(): bool
    {
        return $this->convertFormat !== null
            || $this->convertQuality !== null
            || $this->convertMaxSize !== null;
    }

    /**
     * Get all registered conversion definitions for this slot.
     *
     * @return array<string, ConversionDefinition>
     */
    public function getConversionDefinitions(): array
    {
        return $this->conversions;
    }

    /**
     * Parse a shorthand conversion config array into typed values.
     *
     * Accepts both positional ([width, height]) and named (['width' => ..., 'height' => ...]) formats.
     *
     * @param  array<int|string, mixed>  $config  Shorthand conversion config
     * @return array{width: int|null, height: int|null, quality: int|null, format: string|null, fit: string|null}
     */
    private function parseConversionConfig(array $config): array
    {
        $width = isset($config[0]) && is_numeric($config[0]) ? (int) $config[0] : (isset($config['width']) && is_numeric($config['width']) ? (int) $config['width'] : null);
        $height = isset($config[1]) && is_numeric($config[1]) ? (int) $config[1] : (isset($config['height']) && is_numeric($config['height']) ? (int) $config['height'] : null);
        $quality = isset($config['quality']) && is_numeric($config['quality']) ? (int) $config['quality'] : null;
        $format = isset($config['format']) && is_string($config['format']) ? $config['format'] : null;
        $fit = isset($config['fit']) && is_string($config['fit']) ? $config['fit'] : null;

        return compact('width', 'height', 'quality', 'format', 'fit');
    }
}
