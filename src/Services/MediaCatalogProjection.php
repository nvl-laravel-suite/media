<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Config\Repository;

/** Bounds presentation metadata crossing a granted catalog copy boundary. @internal */
final readonly class MediaCatalogProjection
{
    public function __construct(private Repository $configuration) {}

    /** @return array<string, bool|float|int|string|null> */
    public function metadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $configuredKeys = $this->configuration->get('nvl-media.catalog.metadata_keys', []);
        $approved = [];
        if (is_array($configuredKeys)) {
            foreach ($configuredKeys as $key) {
                if (is_string($key)) {
                    $approved[$key] = true;
                }
            }
        }

        return array_filter(
            $metadata,
            static fn (mixed $value, mixed $key): bool => is_string($key)
                && isset($approved[$key])
                && (is_scalar($value) || $value === null)
                && (! is_string($value) || mb_strlen($value) <= 1_000),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return list<string> */
    public function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $configuredMaximum = $this->configuration->get('nvl-media.catalog.max_tags', 25);
        $configuredLength = $this->configuration->get('nvl-media.catalog.max_tag_length', 100);
        $maximum = max(0, is_int($configuredMaximum) ? $configuredMaximum : 25);
        $length = max(1, is_int($configuredLength) ? $configuredLength : 100);

        return array_slice(array_values(array_filter(
            $tags,
            static fn (mixed $tag): bool => is_string($tag) && $tag !== '' && mb_strlen($tag) <= $length,
        )), 0, $maximum);
    }
}
