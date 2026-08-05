<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use BackedEnum;
use Nvl\Media\Enums\ImageCompression;
use Nvl\Media\Enums\ImageFit;
use Nvl\Media\Enums\ImageFormat;

/**
 * Normalizes typed image-format, preset, and output-conversion configuration.
 */
final class MediaImageConfiguration
{
    /**
     * Resolve normalized image variation presets, optionally filtered by label.
     *
     * @param  list<string>|null  $names
     * @return array<string, array<string, mixed>>
     */
    public static function presets(?array $names = null, bool $enabledOnly = true): array
    {
        $configuredPresets = config('media.image_variation_presets', []);

        if (! is_array($configuredPresets)) {
            return [];
        }

        $requested = is_array($names) && $names !== [] ? array_flip($names) : null;
        $resolved = [];

        foreach ($configuredPresets as $name => $preset) {
            if (! is_string($name)
                || $name === ''
                || ! is_array($preset)
                || ($requested !== null && ! isset($requested[$name]))
                || ($enabledOnly && ! (bool) ($preset['enabled'] ?? true))) {
                continue;
            }

            $resolved[$name] = self::normalizePreset($preset);
        }

        return $resolved;
    }

    /**
     * Resolve the normalized full-size output conversion for a source format.
     *
     * @return array<string, mixed>|null
     */
    public static function outputConversion(string $sourceExtension): ?array
    {
        $configured = config('media.output_conversion', []);

        if (! is_array($configured) || ! (bool) ($configured['enabled'] ?? false)) {
            return null;
        }

        $source = mb_strtolower($sourceExtension);
        $skipFormats = self::stringList($configured['skip_formats'] ?? ['svg', 'gif']);
        $normalized = self::normalizePreset($configured);
        $target = $normalized['format'] ?? null;

        if (in_array($source, $skipFormats, true)
            || (is_string($target) && self::equivalentExtensions($source, $target))) {
            return null;
        }

        return $normalized;
    }

    /**
     * Normalize one preset and inherit defaults from its image-format profile.
     *
     * @param  array<array-key, mixed>  $preset
     * @return array<string, mixed>
     */
    public static function normalizePreset(array $preset): array
    {
        $normalized = self::stringKeyed($preset);
        $format = isset($normalized['format'])
            ? ImageFormat::resolve($normalized['format'])
            : null;
        $profile = $format instanceof ImageFormat ? self::formatProfile($format) : [];
        $normalized = array_replace($profile, $normalized);

        if ($format instanceof ImageFormat) {
            $normalized['format'] = $format->value;
        }

        if (isset($normalized['fit'])) {
            $normalized['fit'] = ImageFit::resolve($normalized['fit'])->value;
        }

        if (isset($normalized['compression'])) {
            $compression = ImageCompression::resolve($normalized['compression']);
            $normalized['compression'] = $compression->value;

            if ($compression === ImageCompression::Lossless) {
                $normalized['quality'] = 100;
            }
        }

        return $normalized;
    }

    /**
     * Resolve a normalized compression and quality profile for one format.
     *
     * @return array<string, mixed>
     */
    private static function formatProfile(ImageFormat $format): array
    {
        $profiles = config('media.image_formats', []);

        if (! is_array($profiles)) {
            return [];
        }

        $profile = $profiles[$format->value] ?? [];

        return is_array($profile) ? self::stringKeyed($profile) : [];
    }

    /**
     * Normalize JPEG aliases when comparing source and output formats.
     */
    private static function equivalentExtensions(string $source, string $target): bool
    {
        $source = $source === 'jpeg' ? 'jpg' : $source;
        $target = $target === 'jpeg' ? 'jpg' : $target;

        return $source === $target;
    }

    /**
     * Retain only string-keyed configuration entries.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value instanceof BackedEnum ? $value->value : $value;
            }
        }

        return $normalized;
    }

    /**
     * Retain only lowercase non-empty string values.
     *
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $value): string => mb_strtolower($value),
            array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== ''),
        ));
    }

    private function __construct() {}
}
