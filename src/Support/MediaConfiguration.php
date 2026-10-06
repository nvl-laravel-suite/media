<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use InvalidArgumentException;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Support\Config\PackageStorage;

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
        return PackageStorage::tableConnection('media', 'owner_slot_operations');
    }

    /**
     * Resolve the validated owner-slot operation ledger table.
     */
    public static function ownerSlotOperationTable(): string
    {
        $table = MediaTables::get(MediaTables::OwnerSlotOperations);

        if (in_array($table, [
            MediaTables::get(MediaTables::Media),
            MediaTables::get(MediaTables::Associations),
            MediaTables::get(MediaTables::ImageVariations),
            MediaTables::get(MediaTables::I18n),
            MediaTables::get(MediaTables::MultipartUploads),
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
