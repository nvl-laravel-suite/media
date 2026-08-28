<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use InvalidArgumentException;
use Nvl\Media\Definitions\Tables\MediaTables;

/**
 * Provides strict, reusable access to untyped Laravel configuration values.
 */
final class MediaConfiguration
{
    /**
     * Resolve the optional owner-slot operation ledger connection.
     */
    public static function ownerSlotOperationConnection(): ?string
    {
        $connection = config('media.owner_slots.idempotency.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException(
                'media.owner_slots.idempotency.connection must be a string or null.',
            );
        }

        return $connection;
    }

    /**
     * Resolve the validated owner-slot operation ledger table.
     */
    public static function ownerSlotOperationTable(): string
    {
        $table = config('media.owner_slots.idempotency.table');

        if (! is_string($table)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
            throw new InvalidArgumentException(
                'media.owner_slots.idempotency.table must be a safe table name.',
            );
        }

        if (in_array($table, [
            MediaTables::Media,
            MediaTables::Associations,
            MediaTables::ImageVariations,
            MediaTables::I18n,
            MediaTables::MultipartUploads,
        ], true)) {
            throw new InvalidArgumentException(
                'media.owner_slots.idempotency.table must not collide with another Media table.',
            );
        }

        return $table;
    }

    public static function string(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function integer(string $key, int $default, int $minimum = 0): int
    {
        $value = config($key, $default);

        return is_int($value) && $value >= $minimum ? $value : $default;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    public static function stringList(string $key, array $default = []): array
    {
        $value = config($key, $default);

        if (! is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param  list<int>  $default
     * @return list<int>
     */
    public static function integerList(string $key, array $default = []): array
    {
        $value = config($key, $default);

        if (! is_array($value)) {
            return $default;
        }

        $integers = [];

        foreach ($value as $item) {
            if (is_int($item)) {
                $integers[] = $item;
            }
        }

        return array_values(array_unique($integers));
    }

    /**
     * Return every non-empty string found in a nested configuration array.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    public static function nestedStringList(string $key, array $default = []): array
    {
        $value = config($key, $default);

        if (! is_array($value)) {
            return $default;
        }

        $strings = [];
        array_walk_recursive($value, static function (mixed $item) use (&$strings): void {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        });

        return array_values(array_unique($strings));
    }

    private function __construct() {}
}
