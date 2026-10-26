<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use BackedEnum;
use Exception;
use Illuminate\Support\Str;
use LogicException;
use OverflowException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Maps Spatie Data objects between API-facing camelCase and model-facing snake_case shapes.
 *
 * @mixin Data
 *
 * @api
 */
trait DataTransform
{
    private const int MAX_TRANSFORM_DEPTH = 64;

    /**
     * Transform this data object into its API-facing array representation.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function toArray(): array
    {
        return $this->transform();
    }

    /**
     * Normalize values for storage.
     *
     * @param  mixed  $value  Incoming data value
     * @return mixed Normalized value
     */
    private function normalizeForStorage(mixed $value, int $depth = 0): mixed
    {
        $this->assertTransformDepth($depth);

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $nestedValue) {
                $normalized[$key] = $this->normalizeForStorage($nestedValue, $depth + 1);
            }

            return $normalized;
        }

        return $value;
    }

    /**
     * Convert array keys to snake_case recursively.
     *
     * @param  array<mixed, mixed>  $data  Input array
     * @return array<array-key, mixed> Snake-cased keys with list keys preserved
     */
    private static function snakeKeysRecursive(array $data, int $depth = 0): array
    {
        self::assertTransformDepth($depth);

        $result = [];

        foreach ($data as $key => $value) {
            $normalizedKey = is_string($key) ? Str::snake($key) : $key;

            if (array_key_exists($normalizedKey, $result)) {
                throw new LogicException(
                    "Data keys [{$key}] and another key normalize to [{$normalizedKey}].",
                );
            }

            if (is_array($value)) {
                $result[$normalizedKey] = self::snakeKeysRecursive($value, $depth + 1);

                continue;
            }

            $result[$normalizedKey] = $value;
        }

        return $result;
    }

    /**
     * Transform this data object into a model-facing snake_case payload.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function toModel(): array
    {
        $normalized = array_map(function ($value) {
            return $this->normalizeForStorage($value);
        }, $this->toArray());

        return self::snakeStringKeys($normalized);
    }

    /**
     * Convert a top-level DTO payload while retaining its string-key contract.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function snakeStringKeys(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $normalizedKey = Str::snake($key);

            if (array_key_exists($normalizedKey, $result)) {
                throw new LogicException(
                    "Data keys [{$key}] and another key normalize to [{$normalizedKey}].",
                );
            }

            $result[$normalizedKey] = is_array($value)
                ? self::snakeKeysRecursive($value, 1)
                : $value;
        }

        return $result;
    }

    /**
     * Build a create payload that omits both Optional values and null defaults.
     *
     * Use this only when null means "use the model or database default". Use
     * toModelPatch() when null is an intentional field clear.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function toModelFiltered(): array
    {
        return self::filterStringModelValues($this->toModel(), omitNull: true);
    }

    /**
     * Build a patch payload that omits Optional values while preserving explicit nulls.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function toModelPatch(): array
    {
        return self::filterStringModelValues($this->toModel(), omitNull: false);
    }

    /**
     * Prefix this data object's validation rules for nested validation.
     *
     * @return array<string, mixed>
     */
    public static function scopedRules(string $prefix): array
    {
        $rules = self::rules();
        $scopedRules = [];

        foreach ($rules as $field => $rule) {
            $scopedRules[$prefix.$field] = $rule;
        }

        return $scopedRules;
    }

    /**
     * Load validation attribute names from module translations and augment with camelCase aliases.
     *
     * @param  string  $namespace  Translation namespace, such as 'articles::articles'
     * @return array<string, string>
     */
    public static function translatedAttributes(string $namespace): array
    {
        $key = str_contains($namespace, '/validation') || str_ends_with($namespace, '::validation')
            ? $namespace.'.attributes'
            : $namespace.'/validation.attributes';
        $attributes = trans($key);

        if (! is_array($attributes)) {
            return [];
        }

        $camelAliased = [];
        foreach ($attributes as $key => $label) {
            if (! is_string($key) || ! is_string($label)) {
                continue;
            }

            $camel = Str::camel($key);
            $camelAliased[$key] = $label;
            $camelAliased[$camel] = $label;
        }

        return $camelAliased;
    }

    /**
     * Load custom validation messages from module translations.
     *
     * @param  string  $namespace  Translation namespace, such as 'articles::articles'
     * @return array<string, mixed>
     */
    public static function translatedMessages(string $namespace): array
    {
        $key = str_contains($namespace, '/validation') || str_ends_with($namespace, '::validation')
            ? $namespace.'.custom'
            : $namespace.'/validation.custom';
        $custom = trans($key);

        if (! is_array($custom)) {
            return [];
        }

        /** @var array<string, mixed> $custom */
        return $custom;
    }

    /**
     * Recursively omit Optional values and optionally omit nulls from model payloads.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function filterModelValues(
        array $values,
        bool $omitNull,
        int $depth = 0,
    ): array {
        self::assertTransformDepth($depth);

        $isList = array_is_list($values);
        $filtered = [];

        foreach ($values as $key => $value) {
            if ($value instanceof Optional || ($omitNull && $value === null)) {
                continue;
            }

            $filtered[$key] = is_array($value)
                ? self::filterModelValues($value, $omitNull, $depth + 1)
                : $value;
        }

        return $isList ? array_values($filtered) : $filtered;
    }

    /**
     * Retain the top-level string-key contract after recursive filtering.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function filterStringModelValues(array $values, bool $omitNull): array
    {
        $filtered = self::filterModelValues($values, $omitNull);
        $stringKeyed = [];

        foreach ($filtered as $key => $value) {
            if (is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }

        return $stringKeyed;
    }

    /**
     * Reject pathological payload nesting before recursion can exhaust the stack.
     */
    private static function assertTransformDepth(int $depth): void
    {
        if ($depth > self::MAX_TRANSFORM_DEPTH) {
            throw new OverflowException(
                'Data transformation exceeded the maximum nesting depth of '
                .self::MAX_TRANSFORM_DEPTH
                .'.',
            );
        }
    }
}
