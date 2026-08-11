<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Nvl\Media\Data\MediaAdoptionResultData;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaPathResolver;
use stdClass;
use Throwable;

/**
 * Plans or applies a reconciled Spatie-style media adoption.
 */
final readonly class AdoptSpatieMediaAction
{
    /**
     * Create the adoption action.
     */
    public function __construct(
        private MediaDiskGateway $disks,
        private MediaFileExistence $existence,
    ) {}

    /**
     * Plan or apply a non-destructive import from staged legacy tables.
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function execute(
        string $sourceTable,
        ?string $translationTable = null,
        ?string $variationTable = null,
        ?string $uploaderType = null,
        string $defaultLocale = 'en',
        bool $apply = false,
    ): MediaAdoptionResultData {
        $this->assertTableName($sourceTable);
        $this->assertOptionalTableName($translationTable);
        $this->assertOptionalTableName($variationTable);
        $this->assertSchema($sourceTable, $translationTable, $variationTable);

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
                $mapped = $this->mapMedia($sourceTable, $row, $uploaderType);
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
                $errors[] = $this->rowError('media', $row, $exception);
            }
        }

        if ($translationTable !== null) {
            foreach (DB::table($translationTable)->orderBy('id')->get() as $row) {
                try {
                    $mapped = $this->mapTranslation(
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
                    $errors[] = $this->rowError('translation', $row, $exception);
                }
            }
        }

        if ($variationTable !== null) {
            foreach (DB::table($variationTable)->orderBy('id')->get() as $row) {
                try {
                    $mapped = $this->mapVariation($sourceTable, $variationTable, $row);
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
                    $errors[] = $this->rowError('variation', $row, $exception);
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
            DB::transaction(function () use (
                $mediaRows,
                $associationRows,
                $translationRows,
                $variationRows,
            ): void {
                $this->insertRows(MediaTables::Media, $mediaRows);
                $this->insertRows(MediaTables::Associations, $associationRows);
                $this->insertRows(MediaTables::I18n, $translationRows);
                $this->insertRows(MediaTables::ImageVariations, $variationRows);
                $this->assertReconciled(MediaTables::Media, $mediaRows);
                $this->assertReconciled(MediaTables::Associations, $associationRows);
                $this->assertReconciled(MediaTables::I18n, $translationRows);
                $this->assertReconciled(MediaTables::ImageVariations, $variationRows);
            });
        }

        return new MediaAdoptionResultData(
            mode: $apply ? 'apply' : 'dry-run',
            ready: $ready,
            sourceMedia: count($mediaRows),
            sourceAssociations: count($associationRows),
            sourceTranslations: count($translationRows),
            sourceVariations: count($variationRows),
            matchedMedia: $this->matchedCount(MediaTables::Media, $mediaRows),
            matchedAssociations: $this->matchedCount(MediaTables::Associations, $associationRows),
            matchedTranslations: $this->matchedCount(MediaTables::I18n, $translationRows),
            matchedVariations: $this->matchedCount(MediaTables::ImageVariations, $variationRows),
            missingPaths: $missingPaths,
            errors: $errors,
        );
    }

    /**
     * Validate the staged source and canonical target table topology.
     */
    private function assertSchema(
        string $sourceTable,
        ?string $translationTable,
        ?string $variationTable,
    ): void {
        if ($sourceTable === MediaTables::Media) {
            throw new LogicException(
                'Stage the legacy Spatie media table under a different name before adoption.',
            );
        }

        foreach ([
            $sourceTable,
            MediaTables::Media,
            MediaTables::Associations,
            MediaTables::I18n,
            MediaTables::ImageVariations,
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
    private function mapMedia(string $sourceTable, stdClass $row, ?string $uploaderType): array
    {
        $legacyId = $this->requiredScalar($row, 'id');
        $mediaId = $this->stableUuid("{$sourceTable}:media", $legacyId);
        $fileName = $this->firstString($row, ['file_name', 'filename', 'name'])
            ?? throw new LogicException('A file_name, filename, or name value is required.');
        $hash = basename($this->firstString($row, ['hash', 'file_name']) ?? $fileName);
        $folder = $this->firstString($row, ['folder']) ?? $legacyId;
        $disk = $this->firstString($row, ['disk'])
            ?? $this->configuredDisk();
        $mimeType = $this->firstString($row, ['mime_type']) ?? 'application/octet-stream';
        $extension = $this->firstString($row, ['extension'])
            ?? mb_strtolower((string) pathinfo($hash, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? mb_substr($extension, 0, 10) : 'bin';
        $this->assertLength($fileName, 255, 'filename');
        $this->assertLength($hash, 255, 'hash');
        $this->assertLength($folder, 255, 'folder');
        $this->assertLength($disk, 25, 'disk');
        $path = implode('/', array_filter([MediaPathResolver::storagePath($folder), $hash]));
        MediaPathResolver::assertSafe($path);
        $exists = $this->existence->existsFresh($disk, $path);
        $digest = $this->firstString($row, ['digest']);

        if ($digest === null && $exists) {
            $digest = $this->disks->checksum($disk, $path);
        }

        $visibility = $this->visibility($row);
        $uploadedBy = $this->firstString($row, ['uploaded_by']);
        $createdAt = $this->property($row, 'created_at') ?? now();
        $updatedAt = $this->property($row, 'updated_at') ?? $createdAt;
        $metadata = $this->mergedJson($row, ['metadata', 'custom_properties']);

        $media = [
            'id' => $mediaId,
            'filename' => $fileName,
            'hash' => $hash,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $this->integer($row, 'size', $exists ? $this->disks->size($disk, $path) : 0),
            'width' => $this->nullableInteger($row, 'width'),
            'height' => $this->nullableInteger($row, 'height'),
            'duration_ms' => $this->nullableInteger($row, 'duration_ms'),
            'disk' => $disk,
            'folder' => $folder,
            'is_public' => $visibility === MediaVisibility::Public,
            'visibility' => $visibility->value,
            'status' => $this->status($row)->value,
            'revision' => max(1, $this->integer($row, 'revision', 1)),
            'available_at' => $this->property($row, 'available_at') ?? $createdAt,
            'quarantined_at' => $this->property($row, 'quarantined_at'),
            'failure_code' => $this->firstString($row, ['failure_code']),
            'failure_context' => $this->json($this->property($row, 'failure_context')),
            'type' => $this->type($row, $mimeType)->value,
            'digest' => $digest ?? hash('sha256', "{$sourceTable}:{$legacyId}"),
            'tags' => $this->json($this->property($row, 'tags')),
            'metadata' => $metadata,
            'variation_definitions' => $this->json($this->property($row, 'variation_definitions')),
            'uploaded_by' => $uploadedBy,
            'uploaded_by_type' => $uploadedBy === null
                ? null
                : ($this->firstString($row, ['uploaded_by_type']) ?? $uploaderType),
            'upload_session_id' => null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'deleted_at' => $this->property($row, 'deleted_at'),
        ];
        $ownerType = $this->firstString($row, ['model_type', 'associable_type']);
        $ownerId = $this->firstString($row, ['model_id', 'associable_id']);
        $collection = $this->firstString($row, ['collection_name', 'collection']) ?? 'default';
        $associationLocale = $this->firstString($row, ['locale']);
        $this->assertLength($collection, 50, 'collection');
        $this->assertLength($ownerType, 255, 'owner type');
        $this->assertLength($ownerId, 255, 'owner id');
        $this->assertLength($associationLocale, 5, 'association locale');
        $association = $ownerType === null || $ownerId === null ? null : [
            'id' => $this->stableUuid("{$sourceTable}:association", implode(':', [
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
            'order' => max(0, $this->integer($row, 'order_column', 0)),
            'is_active' => true,
            'replaced_at' => null,
            'metadata' => $this->json($this->property($row, 'custom_properties')),
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
    private function mapTranslation(
        string $sourceTable,
        string $translationTable,
        stdClass $row,
        string $defaultLocale,
    ): array {
        $legacyId = $this->requiredScalar($row, 'id');
        $legacyMediaId = $this->requiredScalar($row, 'media_id');
        $locale = $this->firstString($row, ['locale']) ?? $defaultLocale;
        $this->assertLength($locale, 35, 'translation locale');

        return [
            'id' => $this->stableUuid("{$translationTable}:translation", $legacyId),
            'media_id' => $this->stableUuid("{$sourceTable}:media", $legacyMediaId),
            'locale' => $locale,
            'title' => $this->firstString($row, ['title', 'name']),
            'alt' => $this->firstString($row, ['alt', 'alt_text']),
            'caption' => $this->firstString($row, ['caption']),
            'description' => $this->firstString($row, ['description']),
            'created_at' => $this->property($row, 'created_at') ?? now(),
            'updated_at' => $this->property($row, 'updated_at') ?? now(),
        ];
    }

    /**
     * Map one legacy variation row to the package variation schema.
     *
     * @return array{media_id: string, storage_path: string, ...}
     */
    private function mapVariation(
        string $sourceTable,
        string $variationTable,
        stdClass $row,
    ): array {
        $legacyId = $this->requiredScalar($row, 'id');
        $legacyMediaId = $this->requiredScalar($row, 'media_id');
        $label = $this->firstString($row, ['label', 'name'])
            ?? throw new LogicException('A variation label or name is required.');
        $storagePath = $this->firstString($row, ['storage_path', 'path']);

        if ($storagePath === null) {
            throw new LogicException('A variation storage_path or path is required.');
        }

        MediaPathResolver::assertSafe($storagePath);
        $this->assertLength($label, 30, 'variation label');
        $this->assertLength($storagePath, 1024, 'variation storage path');

        return [
            'id' => $this->stableUuid("{$variationTable}:variation", $legacyId),
            'media_id' => $this->stableUuid("{$sourceTable}:media", $legacyMediaId),
            'label' => $label,
            'storage_path' => $storagePath,
            'status' => $this->firstString($row, ['status']) ?? 'available',
            'width' => max(0, $this->integer($row, 'width', 0)),
            'height' => max(0, $this->integer($row, 'height', 0)),
            'size' => max(0, $this->integer($row, 'size', 0)),
            'format' => $this->firstString($row, ['format'])
                ?? mb_strtolower((string) pathinfo($storagePath, PATHINFO_EXTENSION))
                ?: 'webp',
            'quality' => min(100, max(0, $this->integer($row, 'quality', 80))),
            'source_revision' => max(1, $this->integer($row, 'source_revision', 1)),
            'attempts' => max(0, $this->integer($row, 'attempts', 0)),
            'failure_context' => $this->json($this->property($row, 'failure_context')),
            'created_at' => $this->property($row, 'created_at') ?? now(),
            'updated_at' => $this->property($row, 'updated_at') ?? now(),
        ];
    }

    /**
     * Insert mapped rows idempotently without deleting or mutating the source.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertRows(string $table, array $rows): void
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
    private function matchedCount(string $table, array $rows): int
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
    private function assertReconciled(string $table, array $rows): void
    {
        $source = count($rows);
        $matched = $this->matchedCount($table, $rows);

        if ($source !== $matched) {
            throw new LogicException(
                "Media adoption reconciled {$matched} of {$source} rows in [{$table}].",
            );
        }
    }

    /**
     * Resolve the configured fallback disk.
     */
    private function configuredDisk(): string
    {
        $disk = config('media.disk', config('filesystems.default', 'local'));

        return is_string($disk) && trim($disk) !== '' ? trim($disk) : 'local';
    }

    /**
     * Resolve one row's canonical visibility.
     */
    private function visibility(stdClass $row): MediaVisibility
    {
        $visibility = $this->firstString($row, ['visibility']);

        if ($visibility !== null && MediaVisibility::tryFrom($visibility) !== null) {
            return MediaVisibility::from($visibility);
        }

        return (bool) ($this->property($row, 'is_public') ?? false)
            ? MediaVisibility::Public
            : MediaVisibility::Private;
    }

    /**
     * Resolve one row's supported lifecycle status.
     */
    private function status(stdClass $row): MediaLifecycleStatus
    {
        $status = $this->firstString($row, ['status']);

        return $status !== null && MediaLifecycleStatus::tryFrom($status) !== null
            ? MediaLifecycleStatus::from($status)
            : MediaLifecycleStatus::Available;
    }

    /**
     * Resolve one row's supported media type.
     */
    private function type(stdClass $row, string $mimeType): MediaType
    {
        $type = $this->firstString($row, ['type']);

        return $type !== null && MediaType::tryFrom($type) !== null
            ? MediaType::from($type)
            : MediaType::fromMimeType($mimeType);
    }

    /**
     * Merge legacy metadata documents into one JSON object.
     *
     * @param  list<string>  $properties
     */
    private function mergedJson(stdClass $row, array $properties): ?string
    {
        $merged = [];

        foreach ($properties as $property) {
            $value = $this->property($row, $property);

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
    private function json(mixed $value): ?string
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
    private function firstString(stdClass $row, array $properties): ?string
    {
        foreach ($properties as $property) {
            $value = $this->property($row, $property);

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
    private function requiredScalar(stdClass $row, string $property): string
    {
        $value = $this->property($row, $property);

        if (! is_int($value) && ! is_string($value)) {
            throw new LogicException("A scalar {$property} value is required.");
        }

        return (string) $value;
    }

    /**
     * Read one property when the source row exposes it.
     */
    private function property(stdClass $row, string $property): mixed
    {
        return property_exists($row, $property) ? $row->{$property} : null;
    }

    /**
     * Read one integer with a safe fallback.
     */
    private function integer(stdClass $row, string $property, int $default): int
    {
        $value = $this->property($row, $property);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Read one nullable integer.
     */
    private function nullableInteger(stdClass $row, string $property): ?int
    {
        $value = $this->property($row, $property);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Produce a stable UUID-compatible identifier for idempotent imports.
     */
    private function stableUuid(string $scope, string $legacyId): string
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
    private function assertTableName(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid media adoption table [{$table}].");
        }
    }

    /**
     * Validate an optional SQL identifier.
     */
    private function assertOptionalTableName(?string $table): void
    {
        if ($table !== null) {
            $this->assertTableName($table);
        }
    }

    /**
     * Reject values that cannot fit the canonical package schema.
     */
    private function assertLength(?string $value, int $maximum, string $field): void
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
    private function rowError(string $resource, stdClass $row, Throwable $exception): string
    {
        $identifier = $this->property($row, 'id');

        return sprintf(
            '%s [%s]: %s',
            $resource,
            is_scalar($identifier) ? (string) $identifier : 'unknown',
            mb_substr($exception->getMessage(), 0, 500),
        );
    }
}
