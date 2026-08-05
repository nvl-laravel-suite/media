<?php

declare(strict_types=1);

namespace Nvl\Media\Conversions;

use InvalidArgumentException;
use Nvl\Media\Enums\ImageCompression;
use Nvl\Media\Enums\ImageFit;
use Nvl\Media\Enums\ImageFormat;
use Nvl\Media\Support\MediaImageConfiguration;
use Nvl\Media\Support\MediaMimeResolver;
use Nvl\Media\Support\MediaQueueConfiguration;

/** ConversionDefinition: fluent builder for image variation processing parameters. */
class ConversionDefinition
{
    private const int PAYLOAD_VERSION = 1;

    /**
     * Build a ConversionDefinition from a config preset array.
     *
     * @param  string  $name  Preset name/label
     * @param  array<string, mixed>  $preset  Preset configuration
     */
    public static function fromPreset(string $name, array $preset): self
    {
        $preset = MediaImageConfiguration::normalizePreset($preset);
        $definition = new self($name);
        $maxSize = $preset['max_size'] ?? null;
        $width = $preset['width'] ?? null;
        $height = $preset['height'] ?? null;

        if ($maxSize !== null) {
            if (! is_int($maxSize) || $maxSize < 1) {
                throw new InvalidArgumentException("Conversion preset [{$name}] max_size must be a positive integer.");
            }

            $fit = ImageFit::resolve($preset['fit'] ?? ImageFit::Max);
            $definition->fit($fit, $maxSize, $maxSize);
        } elseif (isset($preset['fit'])) {
            if (! is_int($width) || ! is_int($height)) {
                throw new InvalidArgumentException("Conversion preset [{$name}] fit requires positive integer width and height values.");
            }

            $definition->fit(ImageFit::resolve($preset['fit']), $width, $height);
        } else {
            if (! is_int($width) && $width !== null) {
                throw new InvalidArgumentException("Conversion preset [{$name}] width must be an integer or null.");
            }

            if (! is_int($height) && $height !== null) {
                throw new InvalidArgumentException("Conversion preset [{$name}] height must be an integer or null.");
            }

            $definition->width($width);

            if ($height !== null) {
                $definition->height($height);
            }
        }

        if (isset($preset['quality'])) {
            if (! is_int($preset['quality'])) {
                throw new InvalidArgumentException("Conversion preset [{$name}] quality must be an integer.");
            }

            $definition->quality($preset['quality']);
        }
        if (isset($preset['format'])) {
            $definition->format(ImageFormat::resolve($preset['format']));
        }
        if (isset($preset['compression'])) {
            $definition->compression(ImageCompression::resolve($preset['compression']));
        }

        return $definition;
    }

    /**
     * Rehydrate a normalized definition persisted by the media package.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(string $name, array $payload): self
    {
        if (($payload['_version'] ?? null) !== self::PAYLOAD_VERSION) {
            return self::fromPreset($name, $payload);
        }

        $definition = new self($name);
        $definition->targetWidth = self::nullableInteger($payload, 'target_width');
        $definition->targetHeight = self::nullableInteger($payload, 'target_height');
        $definition->fitMethod = self::nullableString($payload, 'fit_method');
        $definition->fitWidth = self::nullableInteger($payload, 'fit_width');
        $definition->fitHeight = self::nullableInteger($payload, 'fit_height');
        $definition->cropWidth = self::nullableInteger($payload, 'crop_width');
        $definition->cropHeight = self::nullableInteger($payload, 'crop_height');
        $definition->cropPosition = self::stringValue($payload, 'crop_position', 'center');
        $definition->targetQuality = self::nullableInteger($payload, 'target_quality');
        $definition->outputFormat = self::nullableString($payload, 'output_format');
        $definition->sharpenAmount = self::nullableInteger($payload, 'sharpen_amount');
        $definition->blurAmount = self::nullableInteger($payload, 'blur_amount');
        $definition->applyGreyscale = self::booleanValue($payload, 'apply_greyscale');
        $definition->rotationDegrees = self::nullableInteger($payload, 'rotation_degrees');
        $definition->flipDirection = self::nullableString($payload, 'flip_direction');
        $definition->watermarkPath = self::nullableString($payload, 'watermark_path');
        $definition->watermarkPosition = self::stringValue($payload, 'watermark_position', 'bottom-right');
        $definition->watermarkOpacity = self::integerValue($payload, 'watermark_opacity', 50);
        $definition->backgroundColor = self::nullableString($payload, 'background_color');
        $definition->brightnessAmount = self::nullableInteger($payload, 'brightness_amount');
        $definition->contrastAmount = self::nullableInteger($payload, 'contrast_amount');
        $definition->preserveOriginalFormat = self::booleanValue($payload, 'preserve_original_format');
        $definition->compression = ImageCompression::resolve(
            self::stringValue($payload, 'compression', ImageCompression::Lossy->value),
        );
        $definition->performOnSlots = self::stringListValue($payload, 'perform_on_slots');
        $definition->shouldBeQueued = self::booleanValue(
            $payload,
            'should_be_queued',
            MediaQueueConfiguration::enabled(),
        );
        $definition->queueName = self::nullableString($payload, 'queue_name');
        $definition->enabled = self::booleanValue($payload, 'enabled', true);
        $definition->validate();

        return $definition;
    }

    /**
     * Create a resize conversion (width and/or height, aspect-ratio preserved).
     *
     * @param  string  $name  Conversion label
     * @param  int|null  $width  Target width (null = unconstrained)
     * @param  int|null  $height  Target height (null = unconstrained)
     * @param  int  $quality  Output quality 0–100
     * @param  string|null  $format  Output format (null = preserve original)
     */
    public static function resize(string $name, ?int $width = null, ?int $height = null, int $quality = 85, ?string $format = null): self
    {
        if ($width === null && $height === null) {
            throw new InvalidArgumentException("ConversionDefinition::resize [{$name}]: at least one of width or height is required.");
        }

        $definition = new self($name);
        $definition->width($width);

        if ($height !== null) {
            $definition->height($height);
        }

        $definition->quality($quality);

        if ($format !== null) {
            $definition->format($format);
        }

        return $definition;
    }

    /**
     * Create a crop conversion (exact dimensions, center-cropped by default).
     *
     * @param  string  $name  Conversion label
     * @param  int  $width  Crop width
     * @param  int  $height  Crop height
     * @param  string  $position  Crop anchor position
     * @param  int  $quality  Output quality 0–100
     * @param  string|null  $format  Output format (null = preserve original)
     */
    public static function cropTo(string $name, int $width, int $height, string $position = 'center', int $quality = 85, ?string $format = null): self
    {
        $definition = new self($name);
        $definition->crop($width, $height, $position);
        $definition->quality($quality);

        if ($format !== null) {
            $definition->format($format);
        }

        return $definition;
    }

    /**
     * Create a fit conversion (resize using a named fit method).
     *
     * @param  string  $name  Conversion label
     * @param  string  $method  Fit method: crop, contain, stretch, max
     * @param  int  $width  Fit width
     * @param  int  $height  Fit height
     * @param  int  $quality  Output quality 0–100
     * @param  string|null  $format  Output format (null = preserve original)
     */
    public static function fitTo(string $name, string $method, int $width, int $height, int $quality = 85, ?string $format = null): self
    {
        $allowedMethods = ['crop', 'contain', 'stretch', 'max'];

        if (! in_array($method, $allowedMethods, true)) {
            throw new InvalidArgumentException("ConversionDefinition::fitTo [{$name}]: invalid fit method [{$method}]. Allowed: ".implode(', ', $allowedMethods));
        }

        $definition = new self($name);
        $definition->fit($method, $width, $height);
        $definition->quality($quality);

        if ($format !== null) {
            $definition->format($format);
        }

        return $definition;
    }

    /**
     * Create a format-only conversion (no resize, just re-encode to a target format).
     *
     * @param  string  $name  Conversion label
     * @param  string  $format  Target format (e.g. webp, avif)
     * @param  int  $quality  Output quality 0–100
     */
    public static function formatOnly(string $name, string $format, int $quality = 85): self
    {
        $definition = new self($name);
        $definition->format($format);
        $definition->quality($quality);

        return $definition;
    }

    public ?int $targetWidth = null;

    public ?int $targetHeight = null;

    public ?string $fitMethod = null;

    public ?int $fitWidth = null;

    public ?int $fitHeight = null;

    public ?int $cropWidth = null;

    public ?int $cropHeight = null;

    public string $cropPosition = 'center';

    public ?int $targetQuality = null;

    public ?string $outputFormat = null;

    public ?int $sharpenAmount = null;

    public ?int $blurAmount = null;

    public bool $applyGreyscale = false;

    public ?int $rotationDegrees = null;

    public ?string $flipDirection = null;

    public ?string $watermarkPath = null;

    public string $watermarkPosition = 'bottom-right';

    public int $watermarkOpacity = 50;

    public ?string $backgroundColor = null;

    public ?int $brightnessAmount = null;

    public ?int $contrastAmount = null;

    public bool $preserveOriginalFormat = false;

    public ImageCompression $compression = ImageCompression::Lossy;

    /** @var list<string> */
    public array $performOnSlots = [];

    public bool $shouldBeQueued;

    public ?string $queueName = null;

    public bool $enabled = true;

    /**
     * @return void
     */
    public function __construct(
        public readonly string $name,
    ) {
        $this->shouldBeQueued = MediaQueueConfiguration::enabled();
    }

    public function width(?int $width): static
    {
        $this->targetWidth = $width;

        return $this;
    }

    public function height(int $height): static
    {
        $this->targetHeight = $height;

        return $this;
    }

    public function crop(int $width, int $height, string $position = 'center'): static
    {
        $this->cropWidth = $width;
        $this->cropHeight = $height;
        $this->cropPosition = $position;

        return $this;
    }

    public function fit(ImageFit|string $method, int $width, int $height): static
    {
        $this->fitMethod = ImageFit::resolve($method)->value;
        $this->fitWidth = $width;
        $this->fitHeight = $height;

        return $this;
    }

    public function quality(int $quality): static
    {
        $this->targetQuality = max(0, min(100, $quality));

        return $this;
    }

    public function format(ImageFormat|string $format): static
    {
        $this->outputFormat = ImageFormat::resolve($format)->value;

        return $this;
    }

    public function compression(ImageCompression|string $compression): static
    {
        $this->compression = ImageCompression::resolve($compression);

        if ($this->compression === ImageCompression::Lossless) {
            $this->targetQuality = 100;
        }

        return $this;
    }

    public function sharpen(int $amount): static
    {
        $this->sharpenAmount = $amount;

        return $this;
    }

    public function blur(int $amount): static
    {
        $this->blurAmount = max(0, min(100, $amount));

        return $this;
    }

    public function greyscale(): static
    {
        $this->applyGreyscale = true;

        return $this;
    }

    public function orientation(int $degrees): static
    {
        $this->rotationDegrees = $degrees;

        return $this;
    }

    public function flip(string $direction): static
    {
        $this->flipDirection = $direction;

        return $this;
    }

    public function watermark(string $path, string $position = 'bottom-right', int $opacity = 50): static
    {
        $this->watermarkPath = $path;
        $this->watermarkPosition = $position;
        $this->watermarkOpacity = $opacity;

        return $this;
    }

    public function background(string $color): static
    {
        $this->backgroundColor = $color;

        return $this;
    }

    public function brightness(int $amount): static
    {
        $this->brightnessAmount = $amount;

        return $this;
    }

    public function contrast(int $amount): static
    {
        $this->contrastAmount = $amount;

        return $this;
    }

    public function keepOriginalImageFormat(): static
    {
        $this->preserveOriginalFormat = true;

        return $this;
    }

    /**
     * Restrict this conversion to specific media slots.
     *
     * @param  string  ...$slots  Slot names this conversion applies to
     */
    public function performOnSlots(string ...$slots): static
    {
        $this->performOnSlots = array_values(array_unique(
            array_merge($this->performOnSlots, $slots),
        ));

        return $this;
    }

    public function queued(): static
    {
        $this->shouldBeQueued = true;

        return $this;
    }

    public function nonQueued(): static
    {
        $this->shouldBeQueued = false;

        return $this;
    }

    public function onQueue(?string $queueName = null): static
    {
        $this->shouldBeQueued = true;
        $this->queueName = $queueName;

        return $this;
    }

    public function enabled(bool $enabled = true): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function disabled(): static
    {
        $this->enabled = false;

        return $this;
    }

    /**
     * Validate that the definition has no conflicting settings.
     * Call before executing the conversion to catch misconfiguration early.
     *
     * @throws InvalidArgumentException When conflicting settings are detected
     */
    public function validate(): void
    {
        if ($this->preserveOriginalFormat && $this->outputFormat !== null) {
            throw new InvalidArgumentException(
                "ConversionDefinition [{$this->name}]: cannot set both keepOriginalImageFormat() and format(). Choose one.",
            );
        }

        if ($this->cropWidth !== null && $this->targetWidth !== null) {
            throw new InvalidArgumentException(
                "ConversionDefinition [{$this->name}]: cannot set both crop() and width(). Use fit() for resize-with-crop.",
            );
        }

        if ($this->cropHeight !== null && $this->targetHeight !== null) {
            throw new InvalidArgumentException(
                "ConversionDefinition [{$this->name}]: cannot set both crop() and height(). Use fit() for resize-with-crop.",
            );
        }

        if ($this->fitMethod !== null && ($this->cropWidth !== null || $this->cropHeight !== null)) {
            throw new InvalidArgumentException(
                "ConversionDefinition [{$this->name}]: cannot set both fit() and crop(). Choose one sizing strategy.",
            );
        }
    }

    /**
     * Return a stable, JSON-safe representation suitable for database persistence.
     *
     * @return array{
     *     _version: int,
     *     target_width: int|null,
     *     target_height: int|null,
     *     fit_method: string|null,
     *     fit_width: int|null,
     *     fit_height: int|null,
     *     crop_width: int|null,
     *     crop_height: int|null,
     *     crop_position: string|null,
     *     target_quality: int|null,
     *     output_format: string|null,
     *     sharpen_amount: int|null,
     *     blur_amount: int|null,
     *     apply_greyscale: bool,
     *     rotation_degrees: int|null,
     *     flip_direction: string|null,
     *     watermark_path: string|null,
     *     watermark_position: string|null,
     *     watermark_opacity: int|null,
     *     background_color: string|null,
     *     brightness_amount: int|null,
     *     contrast_amount: int|null,
     *     preserve_original_format: bool,
     *     compression: string,
     *     perform_on_slots: list<string>,
     *     should_be_queued: bool,
     *     queue_name: string|null,
     *     enabled: bool
     * }
     */
    public function toPayload(): array
    {
        $this->validate();

        return [
            '_version' => self::PAYLOAD_VERSION,
            'target_width' => $this->targetWidth,
            'target_height' => $this->targetHeight,
            'fit_method' => $this->fitMethod,
            'fit_width' => $this->fitWidth,
            'fit_height' => $this->fitHeight,
            'crop_width' => $this->cropWidth,
            'crop_height' => $this->cropHeight,
            'crop_position' => $this->cropPosition,
            'target_quality' => $this->targetQuality,
            'output_format' => $this->outputFormat,
            'sharpen_amount' => $this->sharpenAmount,
            'blur_amount' => $this->blurAmount,
            'apply_greyscale' => $this->applyGreyscale,
            'rotation_degrees' => $this->rotationDegrees,
            'flip_direction' => $this->flipDirection,
            'watermark_path' => $this->watermarkPath,
            'watermark_position' => $this->watermarkPosition,
            'watermark_opacity' => $this->watermarkOpacity,
            'background_color' => $this->backgroundColor,
            'brightness_amount' => $this->brightnessAmount,
            'contrast_amount' => $this->contrastAmount,
            'preserve_original_format' => $this->preserveOriginalFormat,
            'compression' => $this->compression->value,
            'perform_on_slots' => $this->performOnSlots,
            'should_be_queued' => $this->shouldBeQueued,
            'queue_name' => $this->queueName,
            'enabled' => $this->enabled,
        ];
    }

    public function shouldBePerformedOn(string $slot): bool
    {
        if (empty($this->performOnSlots)) {
            return true;
        }

        return in_array($slot, $this->performOnSlots, true);
    }

    public function getResultExtension(string $originalExtension): string
    {
        if ($this->preserveOriginalFormat) {
            return $originalExtension;
        }

        return $this->outputFormat ?? $originalExtension;
    }

    public function getResultMimeType(string $originalMimeType): string
    {
        if ($this->preserveOriginalFormat || $this->outputFormat === null) {
            return $originalMimeType;
        }

        $resolved = MediaMimeResolver::extensionToMime($this->outputFormat);

        return $resolved !== 'application/octet-stream' ? $resolved : $originalMimeType;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function nullableInteger(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        if ($value !== null && ! is_int($value)) {
            throw new InvalidArgumentException("Conversion payload [{$key}] must be an integer or null.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function integerValue(array $payload, string $key, int $default): int
    {
        $value = $payload[$key] ?? $default;

        if (! is_int($value)) {
            throw new InvalidArgumentException("Conversion payload [{$key}] must be an integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function nullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("Conversion payload [{$key}] must be a string or null.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringValue(array $payload, string $key, string $default): string
    {
        $value = $payload[$key] ?? $default;

        if (! is_string($value)) {
            throw new InvalidArgumentException("Conversion payload [{$key}] must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function booleanValue(array $payload, string $key, bool $default = false): bool
    {
        $value = $payload[$key] ?? $default;

        if (! is_bool($value)) {
            throw new InvalidArgumentException("Conversion payload [{$key}] must be a boolean.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function stringListValue(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        if (! is_array($value)) {
            throw new InvalidArgumentException("Conversion payload [{$key}] must be a list of strings.");
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("Conversion payload [{$key}] must be a list of strings.");
            }
        }

        return array_values($value);
    }
}
