<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Support\Config\PackageStorage;

/**
 * Provides strict, reusable access to untyped Laravel configuration values.
 */
final class MediaConfiguration
{
    /**
     * Resolve a safe relative folder prefix for one disk, preserving an explicit empty root.
     *
     * @param  string|null  $disk  Disk name, or null for the generic Media default
     * @param  Repository|null  $configuration  Explicit adoption configuration or the active host configuration
     * @return string Normalized relative prefix, including an empty string for the disk root
     *
     * @throws InvalidArgumentException When folder configuration has an invalid type
     * @throws MediaUploadException When the configured prefix is unsafe
     */
    public static function storageRoot(?string $disk = null, ?Repository $configuration = null): string
    {
        $roots = $configuration !== null
            ? $configuration->get('nvl-media.root_folders', [])
            : config('nvl-media.root_folders', []);
        if (! is_array($roots)) {
            throw new InvalidArgumentException('nvl-media.root_folders must be a disk-to-folder map.');
        }
        $disk ??= MediaDiskResolver::resolve();
        $root = array_key_exists($disk, $roots)
            ? $roots[$disk]
            : ($configuration !== null ? $configuration->get('nvl-media.root_folder', '') : config('nvl-media.root_folder', ''));
        if (! is_string($root)) {
            throw new InvalidArgumentException("The media root folder for disk [{$disk}] must be a string; use an empty string for the disk root.");
        }
        MediaPathResolver::assertSafe($root);
        $root = str_replace('\\', '/', rawurldecode($root));
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $root) === 1
            || preg_match('#(?:^|/)\.(?:/|$)#', $root) === 1
            || ($root !== '' && trim($root, '/') === '')) {
            throw new MediaUploadException('Media root folders must be relative; use an empty string for the disk root.');
        }

        return trim((string) preg_replace('#/{2,}#', '/', $root), '/');
    }

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
