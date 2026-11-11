<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Collection;
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

/**
 * Complete native MediaImport operations, including persistence and file effects.
 *
 * @api
 */
final class MediaImport
{
    /** Rows read, verified, inserted and reconciled per query during an adoption. */
    private const int ADOPTION_CHUNK = 250;

    public function __construct(
        private readonly MediaAccessService $access,
        private readonly MediaDiskGateway $disks,
        private readonly MediaFileExistence $existence,
    ) {}

    /**
     * Plan or apply a non-destructive import from legacy tables staged on the Media connection.
     *
     * Rows are read, checked against storage and written in chunks, so memory does not grow with the table.
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
        $connection = MediaModelRegistry::newInstance()->getConnection();
        $this->adoptAssertSchema($connection->getSchemaBuilder(), $sourceTable, $translationTable, $variationTable);

        $scan = function () use ($actor, $connection, $sourceTable, $translationTable, $variationTable, $uploaderType, $defaultLocale, $apply): array {
            $source = ['Media' => 0, 'Associations' => 0, 'Translations' => 0, 'Variations' => 0];
            $matched = $source;
            /** @var list<string> $missingPaths */
            $missingPaths = [];
            /** @var list<string> $errors */
            $errors = [];
            $store = function (string $resource, string $table, array $rows) use ($connection, $apply, &$source, &$matched, &$missingPaths, &$errors): void {
                /** @var list<array<string, mixed>> $rows */
                $source[$resource] += count($rows);
                $matched[$resource] += $this->adoptStoreChunk(
                    $connection,
                    MediaTables::get($table),
                    $rows,
                    $apply && $errors === [] && $missingPaths === [],
                );
            };

            $connection->table($sourceTable)->chunkById(self::ADOPTION_CHUNK, function (Collection $rows) use ($actor, $sourceTable, $uploaderType, $store, &$missingPaths, &$errors): void {
                $media = [];
                $associations = [];

                foreach ($rows as $row) {
                    try {
                        $mapped = $this->adoptMapMedia($actor, $sourceTable, $row, $uploaderType);
                        $media[] = $mapped['media'];

                        if ($mapped['association'] !== null) {
                            $associations[] = $mapped['association'];
                        }

                        if (! $mapped['exists']) {
                            $missingPaths[] = $mapped['media']['disk'].':'.$mapped['path'];
                        }
                    } catch (Throwable $exception) {
                        $errors[] = $this->adoptRowError($actor, 'media', $row, $exception);
                    }
                }

                $store('Media', MediaTables::Media, $media);
                $store('Associations', MediaTables::Associations, $associations);
            });

            if ($translationTable !== null) {
                $connection->table($translationTable)->chunkById(self::ADOPTION_CHUNK, function (Collection $rows) use ($actor, $connection, $sourceTable, $translationTable, $defaultLocale, $store, &$errors): void {
                    $disks = $this->adoptReferencedDisks($actor, $connection, $sourceTable, $rows);
                    $translations = [];

                    foreach ($rows as $row) {
                        try {
                            $mapped = $this->adoptMapTranslation($actor, $sourceTable, $translationTable, $row, $defaultLocale);

                            if (! isset($disks[$mapped['media_id']])) {
                                throw new LogicException('The referenced legacy media row was not mapped.');
                            }

                            $translations[] = $mapped;
                        } catch (Throwable $exception) {
                            $errors[] = $this->adoptRowError($actor, 'translation', $row, $exception);
                        }
                    }

                    $store('Translations', MediaTables::I18n, $translations);
                });
            }

            if ($variationTable !== null) {
                $connection->table($variationTable)->chunkById(self::ADOPTION_CHUNK, function (Collection $rows) use ($actor, $connection, $sourceTable, $variationTable, $store, &$missingPaths, &$errors): void {
                    $disks = $this->adoptReferencedDisks($actor, $connection, $sourceTable, $rows);
                    $variations = [];

                    foreach ($rows as $row) {
                        try {
                            $mapped = $this->adoptMapVariation($actor, $sourceTable, $variationTable, $row);
                            $disk = $disks[$mapped['media_id']] ?? null;

                            if ($disk === null) {
                                throw new LogicException('The referenced legacy media row was not mapped.');
                            }

                            $variations[] = $mapped;

                            if (! $this->existence->existsFresh($disk, $mapped['storage_path'])) {
                                $missingPaths[] = $disk.':'.$mapped['storage_path'];
                            }
                        } catch (Throwable $exception) {
                            $errors[] = $this->adoptRowError($actor, 'variation', $row, $exception);
                        }
                    }

                    $store('Variations', MediaTables::ImageVariations, $variations);
                });
            }

            $missingPaths = array_values(array_unique($missingPaths));
            $ready = $errors === [] && $missingPaths === [];

            if ($apply && ! $ready) {
                throw new LogicException(
                    'Spatie media adoption cannot apply until every mapping error and missing backing path is resolved.',
                );
            }

            if ($apply) {
                foreach (['Media' => MediaTables::Media, 'Associations' => MediaTables::Associations, 'Translations' => MediaTables::I18n, 'Variations' => MediaTables::ImageVariations] as $resource => $table) {
                    if ($source[$resource] !== $matched[$resource]) {
                        throw new LogicException(sprintf('Media adoption reconciled %d of %d rows in [%s].', $matched[$resource], $source[$resource], MediaTables::get($table)));
                    }
                }
            }

            return ['mode' => $apply ? 'apply' : 'dry-run', 'ready' => $ready, 'sourceMedia' => $source['Media'], 'sourceAssociations' => $source['Associations'], 'sourceTranslations' => $source['Translations'], 'sourceVariations' => $source['Variations'], 'matchedMedia' => $matched['Media'], 'matchedAssociations' => $matched['Associations'], 'matchedTranslations' => $matched['Translations'], 'matchedVariations' => $matched['Variations'], 'missingPaths' => $missingPaths, 'errors' => $errors];
        };

        // An apply maps and inserts chunk by chunk inside one transaction: a blocker found in a later chunk rolls every insert back.
        return $apply ? $connection->transaction($scan) : $scan();
    }

    /**
     * Validate the staged source and canonical target table topology.
     */
    private function adoptAssertSchema(
        SchemaBuilder $schema,
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
            if (! $schema->hasTable($table)) {
                throw new LogicException("Required media adoption table [{$table}] is missing.");
            }
        }

        if (! $schema->hasColumn($sourceTable, 'id')) {
            throw new LogicException("Legacy media table [{$sourceTable}] requires an id column.");
        }

        foreach (array_filter([$translationTable, $variationTable]) as $table) {
            if (! $schema->hasTable($table)) {
                throw new LogicException("Configured legacy media table [{$table}] is missing.");
            }

            if (! $schema->hasColumns($table, ['id', 'media_id'])) {
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
     *     path: string,
     *     exists: bool
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
        $this->adoptAssertLength($actor, $mimeType, 255, 'MIME type');
        $path = implode('/', array_filter([MediaPathResolver::storagePath($folder, $disk), $hash], static fn (string $part): bool => $part !== ''));
        MediaPathResolver::assertSafe($path);
        $exists = $this->existence->existsFresh($disk, $path);
        $digest = $this->adoptFirstString($actor, $row, ['digest']);

        if ($digest === null && $exists) {
            $digest = $this->disks->checksum($disk, $path);
        }

        $visibility = $this->adoptVisibility($actor, $row);
        $uploadedBy = $this->adoptFirstString($actor, $row, ['uploaded_by']);
        $uploadedByType = $this->adoptFirstString($actor, $row, ['uploaded_by_type']) ?? $uploaderType;
        $failureCode = $this->adoptFirstString($actor, $row, ['failure_code']);
        $this->adoptAssertLength($actor, $digest, 255, 'digest');
        $this->adoptAssertLength($actor, $uploadedBy, 255, 'uploader id');
        $this->adoptAssertLength($actor, $uploadedByType, 255, 'uploader type');
        $this->adoptAssertLength($actor, $failureCode, 80, 'failure code');
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
            'failure_code' => $failureCode,
            'failure_context' => $this->adoptJson($actor, $this->adoptProperty($actor, $row, 'failure_context')),
            'type' => $this->adoptType($actor, $row, $mimeType)->value,
            'digest' => $digest ?? hash('sha256', "{$sourceTable}:{$legacyId}"),
            'tags' => $this->adoptJson($actor, $this->adoptProperty($actor, $row, 'tags')),
            'metadata' => $metadata,
            'variation_definitions' => $this->adoptJson($actor, $this->adoptProperty($actor, $row, 'variation_definitions')),
            'uploaded_by' => $uploadedBy,
            'uploaded_by_type' => $uploadedBy === null ? null : $uploadedByType,
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
        $this->adoptAssertLength($actor, $associationLocale, 35, 'association locale');
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

        return ['media' => $media, 'association' => $association, 'path' => $path, 'exists' => $exists];
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
        $title = $this->adoptFirstString($actor, $row, ['title', 'name']);
        $alt = $this->adoptFirstString($actor, $row, ['alt', 'alt_text']);
        $this->adoptAssertLength($actor, $locale, 35, 'translation locale');
        $this->adoptAssertLength($actor, $title, 255, 'translation title');
        $this->adoptAssertLength($actor, $alt, 255, 'translation alt text');

        return [
            'id' => $this->adoptStableUuid($actor, "{$translationTable}:translation", $legacyId),
            'media_id' => $this->adoptStableUuid($actor, "{$sourceTable}:media", $legacyMediaId),
            'locale' => $locale,
            'title' => $title,
            'alt' => $alt,
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

        $status = $this->adoptFirstString($actor, $row, ['status']) ?? 'available';
        $format = $this->adoptFirstString($actor, $row, ['format'])
            ?? mb_strtolower((string) pathinfo($storagePath, PATHINFO_EXTENSION))
            ?: 'webp';
        MediaPathResolver::assertSafe($storagePath);
        $this->adoptAssertLength($actor, $label, 30, 'variation label');
        $this->adoptAssertLength($actor, $storagePath, 1024, 'variation storage path');
        $this->adoptAssertLength($actor, $status, 32, 'variation status');
        $this->adoptAssertLength($actor, $format, 10, 'variation format');

        return [
            'id' => $this->adoptStableUuid($actor, "{$variationTable}:variation", $legacyId),
            'media_id' => $this->adoptStableUuid($actor, "{$sourceTable}:media", $legacyMediaId),
            'label' => $label,
            'storage_path' => $storagePath,
            'status' => $status,
            'width' => max(0, $this->adoptInteger($actor, $row, 'width', 0)),
            'height' => max(0, $this->adoptInteger($actor, $row, 'height', 0)),
            'size' => max(0, $this->adoptInteger($actor, $row, 'size', 0)),
            'format' => $format,
            'quality' => min(100, max(0, $this->adoptInteger($actor, $row, 'quality', 80))),
            'source_revision' => max(1, $this->adoptInteger($actor, $row, 'source_revision', 1)),
            'attempts' => max(0, $this->adoptInteger($actor, $row, 'attempts', 0)),
            'failure_context' => $this->adoptJson($actor, $this->adoptProperty($actor, $row, 'failure_context')),
            'created_at' => $this->adoptProperty($actor, $row, 'created_at') ?? now(),
            'updated_at' => $this->adoptProperty($actor, $row, 'updated_at') ?? now(),
        ];
    }

    /**
     * Insert one chunk of mapped rows idempotently when asked, then count how many of its identifiers the target holds.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function adoptStoreChunk(ConnectionInterface $connection, string $table, array $rows, bool $insert): int
    {
        if ($rows === []) {
            return 0;
        }

        if ($insert) {
            $connection->table($table)->insertOrIgnore($rows);
        }

        return $connection->table($table)->whereIn('id', array_column($rows, 'id'))->count();
    }

    /**
     * Resolve the disk of every legacy media row that one chunk of companion rows references.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, string> Canonical media identifier => disk
     */
    private function adoptReferencedDisks(Authenticatable|SystemActor $actor, ConnectionInterface $connection, string $sourceTable, Collection $rows): array
    {
        $legacyIds = $rows->pluck('media_id')
            ->filter(static fn (mixed $id): bool => is_int($id) || is_string($id))
            ->unique()
            ->values()
            ->all();
        $disks = [];

        foreach ($connection->table($sourceTable)->whereIn('id', $legacyIds)->get() as $media) {
            $disks[$this->adoptStableUuid($actor, "{$sourceTable}:media", $this->adoptRequiredScalar($actor, $media, 'id'))]
                = $this->adoptFirstString($actor, $media, ['disk']) ?? $this->adoptConfiguredDisk($actor);
        }

        return $disks;
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
