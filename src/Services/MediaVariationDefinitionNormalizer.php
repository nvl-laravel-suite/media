<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use InvalidArgumentException;
use Nvl\Media\Conversions\ConversionDefinition;

/**
 * Normalizes upload-specific named variation definitions for persistence.
 */
final class MediaVariationDefinitionNormalizer
{
    /**
     * @param  array<array-key, mixed>  $definitions
     * @return array<string, array<string, mixed>>
     */
    public function normalize(array $definitions): array
    {
        $normalized = [];

        foreach ($definitions as $label => $definition) {
            if (! is_string($label)
                || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,29}$/', $label) !== 1) {
                throw new InvalidArgumentException(
                    'Upload variation labels must be object-key-safe strings of at most 30 characters.',
                );
            }

            if ($definition instanceof ConversionDefinition) {
                if ($definition->name !== $label) {
                    throw new InvalidArgumentException(
                        "Upload variation key [{$label}] must match definition name [{$definition->name}].",
                    );
                }

                $resolved = $definition;
            } elseif (is_array($definition)) {
                $resolved = ConversionDefinition::fromPreset(
                    $label,
                    $this->stringKeyed($definition),
                );
            } else {
                throw new InvalidArgumentException(
                    "Upload variation [{$label}] must be a ConversionDefinition or preset array.",
                );
            }

            $normalized[$label] = $resolved->toPayload();
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>|null  $payloads
     * @return array<string, ConversionDefinition>
     */
    public function definitions(?array $payloads): array
    {
        $definitions = [];

        foreach ($payloads ?? [] as $label => $payload) {
            if (is_string($label) && is_array($payload)) {
                $definitions[$label] = ConversionDefinition::fromPayload(
                    $label,
                    $this->stringKeyed($payload),
                );
            }
        }

        return $definitions;
    }

    /**
     * Reject numeric keys before passing persisted definitions to named options.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'Variation definitions must use named configuration keys.',
                );
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
