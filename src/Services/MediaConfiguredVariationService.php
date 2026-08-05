<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Models\Media;
use Nvl\Media\Support\MediaImageConfiguration;

/** MediaConfiguredVariationService resolves config-defined preset and output conversion payloads. */
final class MediaConfiguredVariationService
{
    /**
     * Resolve the preferred named variation label for thumbnail-style previews.
     */
    public function preferredPreviewVariationLabel(): ?string
    {
        $presets = $this->presetConfigs();

        if (isset($presets['thumb'])) {
            return 'thumb';
        }

        $label = array_key_first($presets);

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * Resolve configured preset payloads, optionally filtered by label.
     *
     * @param  list<string>|null  $names
     * @return array<string, array<string, mixed>>
     */
    public function presetConfigs(?array $names = null, bool $enabledOnly = true): array
    {
        return MediaImageConfiguration::presets($names, $enabledOnly);
    }

    /**
     * Resolve configured preset definitions, optionally filtered by label.
     *
     * @param  list<string>|null  $names
     * @return array<string, ConversionDefinition>
     */
    public function presetDefinitions(?array $names = null, bool $enabledOnly = true): array
    {
        /** @var array<string, ConversionDefinition> $definitions */
        $definitions = [];

        foreach ($this->presetConfigs($names, $enabledOnly) as $name => $preset) {
            $definitions[$name] = ConversionDefinition::fromPreset($name, $preset);
        }

        return $definitions;
    }

    /**
     * Resolve all configured definitions for a media item.
     *
     * @param  list<string>|null  $presetNames
     * @return array<string, ConversionDefinition>
     */
    public function configuredDefinitionsFor(
        Media $media,
        bool $includeOutputConversion = true,
        ?array $presetNames = null,
        bool $enabledOnly = true,
        bool $includePresets = true,
    ): array {
        $definitions = $includePresets
            ? $this->presetDefinitions($presetNames, $enabledOnly)
            : [];

        if (! $includeOutputConversion) {
            return $definitions;
        }

        $outputConversion = $this->outputConversionDefinitionFor($media);

        if ($outputConversion instanceof ConversionDefinition) {
            $definitions[$outputConversion->name] = $outputConversion;
        }

        return $definitions;
    }

    /**
     * Resolve queue payloads for configured variations for a media item.
     *
     * @param  list<string>|null  $presetNames
     * @return array<string, array<string, mixed>>
     */
    public function configuredQueuePayloadsFor(
        Media $media,
        bool $includeOutputConversion = true,
        ?array $presetNames = null,
        bool $enabledOnly = true,
        bool $includePresets = true,
    ): array {
        $payloads = $includePresets
            ? $this->presetConfigs($presetNames, $enabledOnly)
            : [];

        if (! $includeOutputConversion) {
            return $payloads;
        }

        $outputConversion = $this->outputConversionPresetFor($media);

        if (is_array($outputConversion)) {
            $payloads['optimized'] = $outputConversion;
        }

        return $payloads;
    }

    /**
     * Resolve the output conversion definition for a media item when applicable.
     */
    public function outputConversionDefinitionFor(Media $media): ?ConversionDefinition
    {
        $preset = $this->outputConversionPresetFor($media);

        if ($preset === null) {
            return null;
        }

        return ConversionDefinition::fromPreset('optimized', $preset);
    }

    /**
     * Resolve the output conversion queue payload for a media item when applicable.
     *
     * @return array<string, mixed>|null
     */
    public function outputConversionPresetFor(Media $media): ?array
    {
        return MediaImageConfiguration::outputConversion($media->extension);
    }
}
