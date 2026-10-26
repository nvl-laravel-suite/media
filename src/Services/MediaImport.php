<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Support\MediaActors;
use Nvl\Support\Enums\SystemActor;
use stdClass;
use Throwable;

/** Complete native MediaImport operations, including persistence and file effects. */
final class MediaImport
{
    public function __construct(
        private readonly MediaAccessService $access,
        private readonly MediaDiskGateway $disks,
        private readonly MediaFileExistence $existence,
    ) {}

    /**
     * Create the adoption action.
     */

    /**
     * Plan or apply a non-destructive import from staged legacy tables.
     *
     * @return array{mode: string, ready: bool, sourceMedia: int, sourceAssociations: int, sourceTranslations: int, sourceVariations: int, matchedMedia: int, matchedAssociations: int, matchedTranslations: int, matchedVariations: int, missingPaths: list<string>, errors: list<string>}
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function adopt(Authenticatable|SystemActor $actor,
        string $sourceTable,
        ?string $translationTable = null,
        ?string $variationTable = null,
        ?string $uploaderType = null,
        string $defaultLocale = 'en',
        bool $apply = false,
    ): array {
        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::ManageStaging);
        $this->adoptAssertTableName($actor, $sourceTable);
        $this->adoptAssertOptionalTableName($actor, $translationTable);
        $this->adoptAssertOptionalTableName($actor, $variationTable);
        $this->adoptAssertSchema($actor, $sourceTable, $translationTable, $variationTable);

        $sourceRows = DB::table($sourceTable)->orderBy('id')->get();
        $mediaRows = [];
        $associationRows = [];
        $translationRows = [];
        $variationRows = [];
        $missingPaths = [];
        $errors = [];
        $mediaDisks = [];

        foreach ($sourceRows as $row) {
            try {
                $mapped = $this->adoptMapMedia($actor, $sourceTable, $row, $uploaderType);
                $mediaRows[] = $mapped['media'];
                $mediaDisks[$mapped['media']['id']] = $mapped['media']['disk'];

                if ($mapped['association'] !== null) {
                    $associationRows[] = $mapped['association'];
                }

                $path = $mapped['path'];

                if (! $this->existence->existsFresh($mapped['media']['disk'], $path)) {
                    $missingPaths[] = $mapped['media']['disk'].':'.$path;
                }
            } catch (Throwable $exception) {
                $errors[] = $this->adoptRowError($actor, 'media', $row, $exception);
            }
        }

        if ($translationTable !== null) {
            foreach (DB::table($translationTable)->orderBy('id')->get() as $row) {
                try {
                    $mapped = $this->adoptMapTranslation($actor,
                        $sourceTable,
                        $translationTable,
                        $row,
                        $defaultLocale,
                    );

                    if (! isset($mediaDisks[$mapped['media_id']])) {
                        throw new LogicException('The referenced legacy media row was not mapped.');
                    }

                    $translationRows[] = $mapped;
                } catch (Throwable $exception) {
                    $errors[] = $this->adoptRowError($actor, 'translation', $row, $exception);
                }
            }
        }

        if ($variationTable !== null) {
            foreach (DB::table($variationTable)->orderBy('id')->get() as $row) {
                try {
                    $mapped = $this->adoptMapVariation($actor, $sourceTable, $variationTable, $row);
                    $disk = $mediaDisks[$mapped['media_id']] ?? null;
                    $path = $mapped['storage_path'];

                    if (! is_string($disk)) {
                        throw new LogicException('The referenced legacy media row was not mapped.');
                    }

                    $variationRows[] = $mapped;

                    if (! $this->existence->existsFresh($disk, $path)) {
                        $missingPaths[] = $disk.':'.$path;
                    }
                } catch (Throwable $exception) {
                    $errors[] = $this->adoptRowError($actor, 'variation', $row, $exception);
                }
            }
        }

        $missingPaths = array_values(array_unique($missingPaths));
        $ready = $errors === [] && $missingPaths === [];

        if ($apply && ! $ready) {
            throw new LogicException(
                'Spatie media adoption cannot apply until every mapping error and missing backing path is resolved.',
            );
        }

        if ($apply) {
            MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($actor,
                $mediaRows,
                $associationRows,
                $translationRows,
                $variationRows,
            ): void {
                $this->adoptInsertRows($actor, MediaTables::get(MediaTables::Media), $mediaRows);
                $this->adoptInsertRows($actor, MediaTables::get(MediaTables::Associations), $associationRows);
                $this->adoptInsertRows($actor, MediaTables::get(MediaTables::I18n), $translationRows);
                $this->adoptInsertRows($actor, MediaTables::get(MediaTables::ImageVariations), $variationRows);
                $this->adoptAssertReconciled($actor, MediaTables::get(MediaTables::Media), $mediaRows);
                $this->adoptAssertReconciled($actor, MediaTables::get(MediaTables::Associations), $associationRows);
                $this->adoptAssertReconciled($actor, MediaTables::get(MediaTables::I18n), $translationRows);
                $this->adoptAssertReconciled($actor, MediaTables::get(MediaTables::ImageVariations), $variationRows);
            });
        }

        return ['mode' => $apply ? 'apply' : 'dry-run', 'ready' => $ready, 'sourceMedia' => count($mediaRows), 'sourceAssociations' => count($associationRows), 'sourceTranslations' => count($translationRows), 'sourceVariations' => count($variationRows), 'matchedMedia' => $this->adoptMatchedCount($actor, MediaTables::get(MediaTables::Media), $mediaRows), 'matchedAssociations' => $this->adoptMatchedCount($actor, MediaTables::get(MediaTables::Associations), $associationRows), 'matchedTranslations' => $this->adoptMatchedCount($actor, MediaTables::get(MediaTables::I18n), $translationRows), 'matchedVariations' => $this->adoptMatchedCount($actor, MediaTables::get(MediaTables::ImageVariations), $variationRows), 'missingPaths' => $missingPaths, 'errors' => $errors];
    }

    /**
     * Validate the staged source and canonical target table topology.
     */
    private function adoptAssertSchema(Authenticatable|SystemActor $actor,
        string $sourceTable,
        ?string $translationTable,
        ?string $variationTable,
    ): void {
        if ($sourceTable === MediaTables::get(MediaTables::Media)) {
            throw new LogicException(
                'Stage the legacy Spatie media table under a different name before adoption.',
            );
        }

        foreach ([
            $sourceTable,
            MediaTables::get(MediaTables::Media),
            MediaTables::get(MediaTables::Associations),
            MediaTables::get(MediaTables::I18n),
            MediaTables::get(MediaTables::ImageVariations),
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException("Required media adoption table [{$table}] is missing.");
            }
        }

        if (! Schema::hasColumn($sourceTable, 'id')) {
            throw new LogicException("Legacy media table [{$sourceTable}] requires an id column.");
        }

        foreach (array_filter([$translationTable, $variationTable]) as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException("Configured legacy media table [{$table}] is missing.");
            }

            if (! Schema::hasColumns($table, ['id', 'media_id'])) {
                throw new LogicException(
                    "Configured legacy media table [{$table}] requires id and media_id columns.",
                );
            }
        }
    }

    /**
     * Map one Spatie-style row to package media and association rows.
     *
     * @return array{
     *     media: array{id: string, disk: string, ...},
     *     association: array<string, mixed>|null,
     *     path: string
     * }
     */
    private function adoptMapMedia(Authenticatable|SystemActor $actor, string $sourceTable, stdClass $row, ?string $uploaderType): array
    {
        $legacyId = $this->adoptRequiredScalar($actor, $row, 'id');
        $mediaId = $this->adoptStableUuid($actor, "{$sourceTable}:media", $legacyId);
        $fileName = $this->adoptFirstString($actor, $row, ['file_name', 'filename', 'name'])
            ?? throw new LogicException('A file_name, filename, or name value is required.');
        $hash = basename($this->adoptFirstString($actor, $row, ['hash', 'file_name']) ?? $fileName);
        $folder = $this->adoptFirstString($actor, $row, ['folder']) ?? $legacyId;
        $disk = $this->adoptFirstString($actor, $row, ['disk'])
            ?? $this->adoptConfiguredDisk($actor);
        $mimeType = $this->adoptFirstString($actor, $row, ['mime_type']) ?? 'application/octet-stream';
        $extension = $this->adoptFirstString($actor, $row, ['extension'])
            ?? mb_strtolower((string) pathinfo($hash, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? mb_substr($extension, 0, 10) : 'bin';
        $this->adoptAssertLength($actor, $fileName, 255, 'filename');
        $this->adoptAssertLength($actor, $hash, 255, 'hash');
        $this->adoptAssertLength($actor, $folder, 255, 'folder');
        $this->adoptAssertLength($actor, $disk, 25, 'disk');
        $path = implode('/', array_filter([MediaPathResolver::storagePath($folder, $disk), $hash], static fn (string $part): bool => $part !== ''));
        MediaPathResolver::assertSafe($path);
        $exists = $this->existence->existsFresh($disk, $path);
        $digest = $this->adoptFirstString($actor, $row, ['digest']);

        if ($digest === null && $exists) {
            $digest = $this->disks->checksum($disk, $path);
        }

        $visibility = $this->adoptVisibility($actor, $row);
        $uploadedBy = $this->adoptFirstString($actor, $row, ['uploaded_by']);
        $createdAt = $this->adoptProperty($actor, $row, 'created_at') ?? now();
        $updatedAt = $this->adoptProperty($actor, $row, 'updated_at') ?? $createdAt;
        $metadata = $this->adoptMergedJson($actor, $row, ['metadata', 'custom_properties']);

        $media = [
            'id' => $mediaId,
            'filename' => $fileName,
            'hash' => $hash,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $this->adoptInteger($actor, $row, 'size', $exists ? $this->disks->size($disk, $path) : 0),
            'width' => $this->adoptNullableInteger($actor, $row, 'width'),
            'height' => $this->adoptNullableInteger($actor, $row, 'height'),
            'duration_ms' => $this->adoptNullableInteger($actor, $row, 'duration_ms'),
            'disk' => $disk,
            'folder' => $folder,
            'is_public' => $visibility === MediaVisibility::Public,
            'visibility' => $visibility->value,
            'status' => $this->adoptStatus($actor, $row)->value,
            'revision' => max(1, $this->adoptInteger($actor, $row, 'revision', 1)),
            'available_at' => $this->adoptProperty($actor, $row, 'available_at') ?? $createdAt,
            'quarantined_at' => $this->adoptProperty($actor, $row, 'quarantined_at'),
            'failure_code' => $this->adoptFirstString($actor, $row, ['failure_code']),
            'failure_context' => $this->adoptJson($actor, $this->adoptProperty($actor, $row, 'failure_context')),
            'type' => $this->adoptType($actor, $row, $mimeType)->value,
            'digest' => $digest ?? hash('sha256', "{$sourceTable}:{$legacyId}"),
            'tags' => $this->adoptJson($actor, $this->adoptProperty($actor, $row, 'tags')),
            'metadata' => $metadata,
            'variation_definitions' => $this->adoptJson($actor, $this->adoptProperty($actor, $row, 'variation_definitions')),
            'uploaded_by' => $uploadedBy,
            'uploaded_by_type' => $uploadedBy === null
                ? null
                : ($this->adoptFirstString($actor, $row, ['uploaded_by_type']) ?? $uploaderType),
            'upload_session_id' => null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'deleted_at' => $this->adoptProperty($actor, $row, 'deleted_at'),
        ];
        $ownerType = $this->adoptFirstString($actor, $row, ['model_type', 'associable_type']);
        $ownerId = $this->adoptFirstString($actor, $row, ['model_id', 'associable_id']);
        $collection = $this->adoptFirstString($actor, $row, ['collection_name', 'collection']) ?? 'default';
        $associationLocale = $this->adoptFirstString($actor, $row, ['locale']);
        $this->adoptAssertLength($actor, $collection, 50, 'collection');
        $this->adoptAssertLength($actor, $ownerType, 255, 'owner type');
        $this->adoptAssertLength($actor, $ownerId, 255, 'owner id');
        $this->adoptAssertLength($actor, $associationLocale, 5, 'association locale');
        $association = $ownerType === null || $ownerId === null ? null : [
            'id' => $this->adoptStableUuid($actor, "{$sourceTable}:association", implode(':', [
                $legacyId,
                $ownerType,
                $ownerId,
                $collection,
            ])),
            'media_id' => $mediaId,
            'associable_type' => $ownerType,
            'associable_id' => $ownerId,
            'collection' => $collection,
            'locale' => $associationLocale,
            'order' => max(0, $this->adoptInteger($actor, $row, 'order_column', 0)),
            'is_active' => true,
            'replaced_at' => null,
            'metadata' => $this->adoptJson($actor, $this->adoptProperty($actor, $row, 'custom_properties')),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];

        return ['media' => $media, 'association' => $association, 'path' => $path];
    }

    /**
     * Map one legacy translation row to the package translation schema.
     *
     * @return array{media_id: string, ...}
     */
    private function adoptMapTranslation(Authenticatable|SystemActor $actor,
        string $sourceTable,
        string $translationTable,
        stdClass $row,
        string $defaultLocale,
    ): array {
        $legacyId = $this->adoptRequiredScalar($actor, $row, 'id');
        $legacyMediaId = $this->adoptRequiredScalar($actor, $row, 'media_id');
        $locale = $this->adoptFirstString($actor, $row, ['locale']) ?? $defaultLocale;
        $this->adoptAssertLength($actor, $locale, 35, 'translation locale');

        return [
            'id' => $this->adoptStableUuid($actor, "{$translationTable}:translation", $legacyId),
            'media_id' => $this->adoptStableUuid($actor, "{$sourceTable}:media", $legacyMediaId),
            'locale' => $locale,
            'title' => $this->adoptFirstString($actor, $row, ['title', 'name']),
            'alt' => $this->adoptFirstString($actor, $row, ['alt', 'alt_text']),
            'caption' => $this->adoptFirstString($actor, $row, ['caption']),
            'description' => $this->adoptFirstString($actor, $row, ['description']),
            'created_at' => $this->adoptProperty($actor, $row, 'created_at') ?? now(),
            'updated_at' => $this->adoptProperty($actor, $row, 'updated_at') ?? now(),
        ];
    }

    /**
     * Map one legacy variation row to the package variation schema.
     *
     * @return array{media_id: string, storage_path: string, ...}
     */
    private function adoptMapVariation(Authenticatable|SystemActor $actor,
        string $sourceTable,
        string $variationTable,
        stdClass $row,
    ): array {
        $legacyId = $this->adoptRequiredScalar($actor, $row, 'id');
        $legacyMediaId = $this->adoptRequiredScalar($actor, $row, 'media_id');
        $label = $this->adoptFirstString($actor, $row, ['label', 'name'])
            ?? throw new LogicException('A variation label or name is required.');
        $storagePath = $this->adoptFirstString($actor, $row, ['storage_path', 'path']);

        if ($storagePath === null) {
            throw new LogicException('A variation storage_path or path is required.');
        }

        MediaPathResolver::assertSafe($storagePath);
        $this->adoptAssertLength($actor, $label, 30, 'variation label');
        $this->adoptAssertLength($actor, $storagePath, 1024, 'variation storage path');

        return [
            'id' => $this->adoptStableUuid($actor, "{$variationTable}:variation", $legacyId),
            'media_id' => $this->adoptStableUuid($actor, "{$sourceTable}:media", $legacyMediaId),
            'label' => $label,
            'storage_path' => $storagePath,
            'status' => $this->adoptFirstString($actor, $row, ['status']) ?? 'available',
            'width' => max(0, $this->adoptInteger($actor, $row, 'width', 0)),
            'height' => max(0, $this->adoptInteger($actor, $row, 'height', 0)),
            'size' => max(0, $this->adoptInteger($actor, $row, 'size', 0)),
            'format' => $this->adoptFirstString($actor, $row, ['format'])
                ?? mb_strtolower((string) pathinfo($storagePath, PATHINFO_EXTENSION))
                ?: 'webp',
            'quality' => min(100, max(0, $this->adoptInteger($actor, $row, 'quality', 80))),
            'source_revision' => max(1, $this->adoptInteger($actor, $row, 'source_revision', 1)),
            'attempts' => max(0, $this->adoptInteger($actor, $row, 'attempts', 0)),
            'failure_context' => $this->adoptJson($actor, $this->adoptProperty($actor, $row, 'failure_context')),
            'created_at' => $this->adoptProperty($actor, $row, 'created_at') ?? now(),
            'updated_at' => $this->adoptProperty($actor, $row, 'updated_at') ?? now(),
        ];
    }

    /**
     * Insert mapped rows idempotently without deleting or mutating the source.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function adoptInsertRows(Authenticatable|SystemActor $actor, string $table, array $rows): void
    {
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk);
        }
    }

    /**
     * Count deterministic mapped identifiers already present in a target table.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function adoptMatchedCount(Authenticatable|SystemActor $actor, string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return DB::table($table)
            ->whereIn('id', array_column($rows, 'id'))
            ->count();
    }

    /**
     * Fail the transaction when deterministic source and target counts diverge.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function adoptAssertReconciled(Authenticatable|SystemActor $actor, string $table, array $rows): void
    {
        $source = count($rows);
        $matched = $this->adoptMatchedCount($actor, $table, $rows);

        if ($source !== $matched) {
            throw new LogicException(
                "Media adoption reconciled {$matched} of {$source} rows in [{$table}].",
            );
        }
    }

    /**
     * Resolve the configured fallback disk.
     */
    private function adoptConfiguredDisk(Authenticatable|SystemActor $actor): string
    {
        $disk = config('nvl-media.disk', config('filesystems.default', 'local'));

        return is_string($disk) && trim($disk) !== '' ? trim($disk) : 'local';
    }

    /**
     * Resolve one row's canonical visibility.
     */
    private function adoptVisibility(Authenticatable|SystemActor $actor, stdClass $row): MediaVisibility
    {
        $visibility = $this->adoptFirstString($actor, $row, ['visibility']);

        if ($visibility !== null && MediaVisibility::tryFrom($visibility) !== null) {
            return MediaVisibility::from($visibility);
        }

        return (bool) ($this->adoptProperty($actor, $row, 'is_public') ?? false)
            ? MediaVisibility::Public
            : MediaVisibility::Private;
    }

    /**
     * Resolve one row's supported lifecycle status.
     */
    private function adoptStatus(Authenticatable|SystemActor $actor, stdClass $row): MediaLifecycleStatus
    {
        $status = $this->adoptFirstString($actor, $row, ['status']);

        return $status !== null && MediaLifecycleStatus::tryFrom($status) !== null
            ? MediaLifecycleStatus::from($status)
            : MediaLifecycleStatus::Available;
    }

    /**
     * Resolve one row's supported media type.
     */
    private function adoptType(Authenticatable|SystemActor $actor, stdClass $row, string $mimeType): MediaType
    {
        $type = $this->adoptFirstString($actor, $row, ['type']);

        return $type !== null && MediaType::tryFrom($type) !== null
            ? MediaType::from($type)
            : MediaType::fromMimeType($mimeType);
    }

    /**
     * Merge legacy metadata documents into one JSON object.
     *
     * @param  list<string>  $properties
     */
    private function adoptMergedJson(Authenticatable|SystemActor $actor, stdClass $row, array $properties): ?string
    {
        $merged = [];

        foreach ($properties as $property) {
            $value = $this->adoptProperty($actor, $row, $property);

            if ($value === null || $value === '') {
                continue;
            }

            $decoded = is_string($value)
                ? json_decode($value, true, flags: JSON_THROW_ON_ERROR)
                : $value;

            if (is_array($decoded)) {
                $merged = [...$merged, ...$decoded];
            }
        }

        return $merged === [] ? null : json_encode($merged, JSON_THROW_ON_ERROR);
    }

    /**
     * Normalize a legacy JSON value for query-builder insertion.
     */
    private function adoptJson(Authenticatable|SystemActor $actor, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            json_decode($value, true, flags: JSON_THROW_ON_ERROR);

            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Return the first non-empty string from a row.
     *
     * @param  list<string>  $properties
     */
    private function adoptFirstString(Authenticatable|SystemActor $actor, stdClass $row, array $properties): ?string
    {
        foreach ($properties as $property) {
            $value = $this->adoptProperty($actor, $row, $property);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Resolve a required scalar identifier as a string.
     */
    private function adoptRequiredScalar(Authenticatable|SystemActor $actor, stdClass $row, string $property): string
    {
        $value = $this->adoptProperty($actor, $row, $property);

        if (! is_int($value) && ! is_string($value)) {
            throw new LogicException("A scalar {$property} value is required.");
        }

        return (string) $value;
    }

    /**
     * Read one property when the source row exposes it.
     */
    private function adoptProperty(Authenticatable|SystemActor $actor, stdClass $row, string $property): mixed
    {
        return property_exists($row, $property) ? $row->{$property} : null;
    }

    /**
     * Read one integer with a safe fallback.
     */
    private function adoptInteger(Authenticatable|SystemActor $actor, stdClass $row, string $property, int $default): int
    {
        $value = $this->adoptProperty($actor, $row, $property);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Read one nullable integer.
     */
    private function adoptNullableInteger(Authenticatable|SystemActor $actor, stdClass $row, string $property): ?int
    {
        $value = $this->adoptProperty($actor, $row, $property);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Produce a stable UUID-compatible identifier for idempotent imports.
     */
    private function adoptStableUuid(Authenticatable|SystemActor $actor, string $scope, string $legacyId): string
    {
        if (Str::isUuid($legacyId)) {
            return mb_strtolower($legacyId);
        }

        $hex = hash('sha256', $scope."\0".$legacyId);

        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            dechex((hexdec($hex[16]) & 0x3) | 0x8),
            substr($hex, 17, 3),
            substr($hex, 20, 12),
        );
    }

    /**
     * Validate a required SQL identifier before using a dynamic table name.
     */
    private function adoptAssertTableName(Authenticatable|SystemActor $actor, string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid media adoption table [{$table}].");
        }
    }

    /**
     * Validate an optional SQL identifier.
     */
    private function adoptAssertOptionalTableName(Authenticatable|SystemActor $actor, ?string $table): void
    {
        if ($table !== null) {
            $this->adoptAssertTableName($actor, $table);
        }
    }

    /**
     * Reject values that cannot fit the canonical package schema.
     */
    private function adoptAssertLength(Authenticatable|SystemActor $actor, ?string $value, int $maximum, string $field): void
    {
        if ($value !== null && mb_strlen($value) > $maximum) {
            throw new LogicException(
                "Legacy {$field} exceeds the canonical {$maximum}-character limit.",
            );
        }
    }

    /**
     * Format one bounded source-row mapping error.
     */
    private function adoptRowError(Authenticatable|SystemActor $actor, string $resource, stdClass $row, Throwable $exception): string
    {
        $identifier = $this->adoptProperty($actor, $row, 'id');

        return sprintf(
            '%s [%s]: %s',
            $resource,
            is_scalar($identifier) ? (string) $identifier : 'unknown',
            mb_substr($exception->getMessage(), 0, 500),
        );
    }
}
