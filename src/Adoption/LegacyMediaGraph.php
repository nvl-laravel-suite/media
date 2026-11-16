<?php

declare(strict_types=1);

namespace Nvl\Media\Adoption;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Ownership\MediaOwnership;
use Nvl\Media\Services\MediaAccessService;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Locales\LocaleCode;
use stdClass;

/**
 * Maps, validates and scans the legacy px_media graph for MediaSchemaAdopter and MediaAdoptionAdapter.
 *
 * @internal
 *
 * @phpstan-type Source array{connection?: string, root_folder?: string, conversions_folder?: string, uploader_type?: string, owner_types?: array<string,string>}
 * @phpstan-type Counts array{assets:int, associations:int, variations:int, translations:int}
 * @phpstan-type Scan array{schema_fingerprint:string, fingerprint:string, source_fingerprint:string, target_digest:string, source:Counts, staged:Counts, missing:int, changed:int, extra:int, conflicts:list<string>, problems:list<string>}
 * @phpstan-type Mapped array{id: string, row: array<string,mixed>|null, error: string|null}
 */
final readonly class LegacyMediaGraph
{
    /** Resource => [legacy table, native table key, legacy columns, native identity]. */
    public const array Graph = [
        'assets' => [
            'px_media', MediaTables::Media,
            ['filename', 'hash', 'extension', 'mime_type', 'size', 'disk', 'folder', 'is_public', 'type', 'digest', 'tags', 'metadata', 'uploaded_by', 'deleted_at'],
            ['disk', 'folder', 'hash'],
        ],
        'associations' => [
            'px_media_associations', MediaTables::Associations,
            ['media_id', 'associable_type', 'associable_id', 'collection', 'locale', 'order', 'metadata'],
            ['media_id', 'associable_type', 'associable_id', 'collection'],
        ],
        'variations' => [
            'px_media_image_variations', MediaTables::ImageVariations,
            ['media_id', 'label', 'width', 'height', 'size', 'format', 'quality'],
            ['media_id', 'label'],
        ],
        'translations' => [
            'px_media_translations', MediaTables::I18n,
            ['media_id', 'locale', 'title', 'alt', 'caption'],
            ['media_id', 'locale'],
        ],
    ];

    private const array CommonColumns = ['id', 'created_at', 'updated_at'];

    private const array NativeColumns = [
        'assets' => ['visibility', 'uploaded_by_type', 'status', 'revision', 'available_at', 'upload_session_id',
            'variation_definitions', 'quarantined_at', 'failure_code', 'failure_context', 'width', 'height', 'duration_ms'],
        'associations' => ['is_active', 'replaced_at'],
        'variations' => ['storage_path', 'source_revision', 'status', 'attempts', 'failure_context'],
        'translations' => ['description'],
    ];

    public function __construct(
        private DatabaseManager $database,
        private MediaAccessService $access,
        private MediaFileExistence $existence,
    ) {}

    /**
     * Authorize staging and reject unsafe folders or an already declared Ownership integration.
     *
     * @param  Source  $source
     */
    public function admit(Authenticatable|SystemActor $actor, array $source, bool $committed = false): void
    {
        $this->access->authorize($actor, MediaAbility::ManageStaging);
        if (MediaOwnership::declared()) {
            throw new LogicException('Stage legacy Media into standalone storage before native Ownership adoption.');
        }
        if ($committed && $this->target()->transactionLevel() !== 0) {
            throw new LogicException('Media staging and verification require a target outside existing transactions.');
        }
        foreach ([$source['root_folder'] ?? '', $source['conversions_folder'] ?? 'conversions'] as $folder) {
            MediaPathResolver::assertSafe($folder);
            if (trim($folder, '/') !== $folder || str_contains($folder, '\\')) {
                throw new InvalidArgumentException('Legacy Media folders must be relative disk paths.');
            }
        }
    }

    /**
     * Validate untyped manifest options as a legacy source description.
     *
     * @param  array<string, mixed>  $options
     * @return Source
     */
    public static function source(array $options): array
    {
        $source = [];
        foreach ($options as $key => $value) {
            if (in_array($key, ['connection', 'root_folder', 'conversions_folder', 'uploader_type'], true) && is_string($value)) {
                $source[$key] = $value;
            } elseif ($key === 'owner_types' && is_array($value) && array_filter($value, is_string(...)) === $value
                && array_filter(array_keys($value), is_string(...)) === array_keys($value)) {
                /** @var array<string, string> $value */
                $source['owner_types'] = $value;
            } else {
                throw new InvalidArgumentException("Media adoption option [{$key}] is unknown or has an invalid value.");
            }
        }

        return $source;
    }

    public function target(): Connection
    {
        return MediaModelRegistry::newInstance()->getConnection();
    }

    /** @param Source $source */
    public function origin(array $source): Connection
    {
        return isset($source['connection']) ? $this->database->connection($source['connection']) : $this->target();
    }

    /**
     * Resolve the prefixed native table of a resource.
     */
    public function table(string $resource): string
    {
        return MediaTables::get(self::Graph[$resource][1]);
    }

    /**
     * Read the whole legacy graph and compare it with the native tables, optionally staging missing rows per page.
     *
     * @param  Source  $source
     * @return Scan
     */
    public function scan(array $source, bool $write = false): array
    {
        $target = $this->target();
        $origin = $this->origin($source);
        $schemas = [];
        foreach (self::Graph as $resource => [$legacy, $native, $columns, $identity]) {
            $table = MediaTables::get($native);
            if ($origin === $target && $table === $legacy) {
                throw new LogicException('Media adoption requires distinct legacy and native tables.');
            }
            $schemas[] = $this->schema($origin, $legacy, [...self::CommonColumns, ...$columns], strict: true);
            $schemas[] = $this->schema($target, $table, [...self::CommonColumns, ...$columns, ...self::NativeColumns[$resource]], uniqueness: $native === MediaTables::Media ? [] : $identity);
        }

        $schemaFingerprint = hash('sha256', json_encode($schemas, JSON_THROW_ON_ERROR));
        $hash = hash_init('sha256');
        $sourceHash = hash_init('sha256');
        $targetHash = hash_init('sha256');
        $conflicts = $problems = [];
        $missing = $changed = $extra = 0;
        $sourceCounts = $stagedCounts = ['assets' => 0, 'associations' => 0, 'variations' => 0, 'translations' => 0];
        foreach (self::Graph as $resource => [$legacy, , , $identity]) {
            $table = $this->table($resource);
            $seen = [];
            $changedRows = 0;
            hash_update($hash, $legacy."\n");
            hash_update($sourceHash, $legacy."\n");
            hash_update($targetHash, $table."\n");
            foreach ($origin->table($legacy)->useWritePdo()->lazyById(75)->chunk(75) as $page) {
                /** @var list<stdClass> $legacyRows */
                $legacyRows = array_values($page->all());
                $existing = $target->table($table)->useWritePdo()->whereIn('id', array_column($legacyRows, 'id'))->get()->keyBy('id');
                $pending = [];
                foreach ($this->mapRows($origin, $resource, $legacyRows, $source) as $index => ['id' => $id, 'row' => $row, 'error' => $error]) {
                    $sourceCounts[$resource]++;
                    $encoded = json_encode($legacyRows[$index], JSON_THROW_ON_ERROR)."\n";
                    hash_update($hash, $encoded);
                    hash_update($sourceHash, $encoded);
                    if ($row === null) {
                        $conflicts[] = $problems[] = (string) $error;

                        continue;
                    }
                    hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
                    $key = json_encode(array_intersect_key($row, array_flip($identity)), JSON_THROW_ON_ERROR);
                    if (isset($seen[$key])) {
                        $conflicts[] = $problems[] = "{$legacy} [{$id}] conflicts with legacy row [{$seen[$key]}] on its native identity.";
                    }
                    $seen[$key] = $id;
                    $current = $existing->get($id);
                    if ($current === null) {
                        $pending[] = $row;
                    } elseif ($this->matches($row, $current)) {
                        $stagedCounts[$resource]++;
                        hash_update($targetHash, json_encode($current, JSON_THROW_ON_ERROR)."\n");
                    } else {
                        $changedRows++;
                        $conflicts[] = "{$table} [{$id}] differs from the reviewed legacy row.";
                    }
                }
                if ($write && $conflicts !== []) {
                    throw new LogicException('Media adoption has conflicts. '.$conflicts[0]);
                }
                if ($write && $pending !== []) {
                    $target->transaction(static function () use ($target, $table, $pending): void {
                        foreach (array_chunk($pending, 30) as $batch) {
                            $target->table($table)->insert($batch);
                        }
                    });
                    $stagedCounts[$resource] += count($pending);
                } else {
                    $missing += count($pending);
                }
            }
            $count = $target->table($table)->useWritePdo()->count();
            if ($count !== $stagedCounts[$resource]) {
                $conflicts[] = "{$table} contains rows outside the matching staged legacy graph.";
            }
            $changed += $changedRows;
            $extra += max(0, $count - $stagedCounts[$resource] - $changedRows);
        }

        return [
            'schema_fingerprint' => $schemaFingerprint,
            'fingerprint' => hash('sha256', $schemaFingerprint.hash_final($hash)),
            'source_fingerprint' => hash('sha256', $schemaFingerprint.hash_final($sourceHash)),
            'target_digest' => hash('sha256', hash_final($targetHash).json_encode($stagedCounts, JSON_THROW_ON_ERROR)),
            'source' => $sourceCounts, 'staged' => $stagedCounts, 'missing' => $missing, 'changed' => $changed, 'extra' => $extra,
            'conflicts' => $conflicts, 'problems' => $problems,
        ];
    }

    /**
     * Map one page of legacy rows, including their backing-file checks, in order.
     *
     * @param  list<stdClass>  $legacyRows
     * @param  Source  $source
     * @return list<Mapped>
     */
    public function mapRows(Connection $origin, string $resource, array $legacyRows, array $source): array
    {
        $legacy = self::Graph[$resource][0];
        $parents = $resource === 'assets' ? collect() : $origin->table('px_media')->useWritePdo()
            ->whereIn('id', array_column($legacyRows, 'media_id'))->get()->keyBy('id');
        $mapped = [];
        foreach ($legacyRows as $legacyRow) {
            $legacyId = is_string($legacyRow->id) ? $legacyRow->id : '(invalid ID)';
            try {
                $id = $this->uuid($legacyRow->id);
                $parent = $resource !== 'assets' ? $parents->get($this->uuid($legacyRow->media_id)) : null;
                $mapped[] = ['id' => $id, 'row' => ['id' => $id] + $this->map($resource, $legacyRow, $source, $parent instanceof stdClass ? $parent : null), 'error' => null];
            } catch (InvalidArgumentException|LogicException|MediaUploadException|JsonException $exception) {
                $mapped[] = ['id' => $legacyId, 'row' => null, 'error' => "{$legacy} [{$legacyId}] ".$exception->getMessage()];
            }
        }

        return $mapped;
    }

    /**
     * Compare a mapped row with a stored native row, tolerating driver scalar and JSON formatting differences.
     *
     * @param  array<string,mixed>  $mapped
     */
    public function matches(array $mapped, stdClass $existing): bool
    {
        foreach ($mapped as $key => $value) {
            $actual = $existing->{$key} ?? null;
            if ($value === $actual) {
                continue;
            }
            if ((is_bool($value) || is_int($value)) && (is_bool($actual) || is_int($actual) || is_string($actual)) && (string) (is_bool($value) ? (int) $value : $value) === (string) (is_bool($actual) ? (int) $actual : $actual)) {
                continue;
            }
            if (in_array($key, ['tags', 'metadata'], true) && is_string($value) && is_string($actual)
                && json_decode($value, true, flags: JSON_THROW_ON_ERROR) === json_decode($actual, true, flags: JSON_THROW_ON_ERROR)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $uniqueness
     * @return array<string,mixed>
     */
    private function schema(Connection $connection, string $table, array $required, bool $strict = false, array $uniqueness = []): array
    {
        $schema = $connection->getSchemaBuilder();
        $columns = $schema->getColumns($table);
        $names = array_column($columns, 'name');
        if (array_diff($required, $names) !== [] || ($strict && array_diff($names, $required) !== [])
            || ! $schema->hasIndex($table, ['id'], 'primary') || ($uniqueness !== [] && ! $schema->hasIndex($table, $uniqueness, 'unique'))) {
            throw new LogicException("Media adoption table [{$table}] has an incompatible schema.");
        }

        return [
            'connection' => $connection->getName(), 'database' => $connection->getDatabaseName(), 'driver' => $connection->getDriverName(),
            'table' => $connection->getTablePrefix().$table, 'columns' => $columns,
            'indexes' => $schema->getIndexes($table), 'foreign_keys' => $schema->getForeignKeys($table),
        ];
    }

    /**
     * @param  Source  $source
     * @return array<string,mixed>
     */
    private function map(string $resource, stdClass $legacy, array $source, ?stdClass $parent): array
    {
        $timestamps = ['created_at' => $legacy->created_at, 'updated_at' => $legacy->updated_at];
        if ($resource === 'assets') {
            return [...$this->asset($legacy, $source), ...$timestamps];
        }
        if ($parent === null) {
            throw new LogicException('has no referenced legacy asset.');
        }
        $mediaId = $this->uuid($legacy->media_id);
        $attributes = match ($resource) {
            'associations' => $this->association($legacy, $source),
            'variations' => $this->variation($legacy, $parent, $source),
            'translations' => [
                'locale' => (new LocaleCode($this->string($legacy->locale, 'locale', 35)))->value,
                'title' => $this->nullableString($legacy->title, 'title', 255),
                'alt' => $this->nullableString($legacy->alt, 'alt', 255),
                'caption' => $legacy->caption, 'description' => null,
            ],
            default => throw new LogicException('has an unsupported graph resource.'),
        };

        return [...$attributes, ...$timestamps, 'media_id' => $mediaId];
    }

    /**
     * @param  Source  $source
     * @return array<string,mixed>
     */
    private function asset(stdClass $row, array $source): array
    {
        [$disk, $folder, $hash, $parentPath] = $this->storage($row, $source);
        if (MediaPathResolver::storagePath($folder ?? '', $disk) !== $parentPath) {
            throw new LogicException("requires the native root for disk [{$disk}] to match the legacy root.");
        }
        $this->requireFile($disk, $this->path($parentPath, $hash));
        $uploaderType = $source['uploader_type'] ?? null;
        if ($row->uploaded_by !== null && $uploaderType === null) {
            throw new LogicException('requires an explicit uploader morph type for its historical uploader.');
        }
        $isPublic = match ($row->is_public) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => throw new LogicException('has an invalid public visibility flag.'),
        };
        $type = MediaType::tryFrom($this->string($row->type, 'type', 15))
            ?? throw new LogicException('has an unknown Media type.');

        return [
            'filename' => $this->string($row->filename, 'filename', 255), 'hash' => $hash,
            'extension' => $this->string($row->extension, 'extension', 10),
            'mime_type' => $this->string($row->mime_type, 'mime_type', 255), 'size' => $this->number($row->size),
            'disk' => $disk, 'folder' => $folder, 'is_public' => $isPublic, 'visibility' => $isPublic ? 'public' : 'private',
            'type' => $type->value, 'digest' => $this->string($row->digest, 'digest', 255),
            'tags' => $this->json($row->tags), 'metadata' => $this->json($row->metadata),
            'uploaded_by' => $row->uploaded_by === null ? null : $this->uuid($row->uploaded_by),
            'uploaded_by_type' => $row->uploaded_by === null ? null : $this->string($uploaderType, 'uploader type', 255),
            'deleted_at' => $row->deleted_at, 'status' => 'available', 'revision' => 1, 'available_at' => $row->created_at,
            'upload_session_id' => null, 'variation_definitions' => null, 'quarantined_at' => null,
            'failure_code' => null, 'failure_context' => null, 'width' => null, 'height' => null, 'duration_ms' => null,
        ];
    }

    /**
     * @param  Source  $source
     * @return array<string,mixed>
     */
    private function association(stdClass $row, array $source): array
    {
        $type = $this->string($row->associable_type, 'owner type', 255);

        return [
            'associable_type' => $this->string($source['owner_types'][$type] ?? $type, 'owner type', 255),
            'associable_id' => $this->string($row->associable_id, 'owner ID', 255),
            'collection' => $this->string($row->collection, 'collection', 50),
            'locale' => $row->locale === null ? null : (new LocaleCode($this->string($row->locale, 'locale', 35)))->value,
            'order' => $this->number($row->order, 2147483647), 'metadata' => $this->json($row->metadata),
            'is_active' => true, 'replaced_at' => null,
        ];
    }

    /**
     * @param  Source  $source
     * @return array<string,mixed>
     */
    private function variation(stdClass $row, stdClass $parent, array $source): array
    {
        [$disk, , $hash, $folder] = $this->storage($parent, $source);
        $label = $this->filename($row->label, 'label', 30);
        $format = $this->filename($row->format, 'format', 10);
        $path = $this->path($folder, $source['conversions_folder'] ?? 'conversions', pathinfo($hash, PATHINFO_FILENAME).'-'.$label.'.'.$format);
        $this->requireFile($disk, $path);

        return [
            'storage_path' => $this->string($path, 'variation path', 1024), 'label' => $label, 'format' => $format,
            'width' => $this->number($row->width, 2147483647), 'height' => $this->number($row->height, 2147483647),
            'size' => $this->number($row->size), 'quality' => $this->number($row->quality, 100),
            'source_revision' => 1, 'status' => 'available', 'attempts' => 0, 'failure_context' => null,
        ];
    }

    /**
     * @param  Source  $source
     * @return array{string, string|null, string, string}
     */
    private function storage(stdClass $row, array $source): array
    {
        $disk = $this->string($row->disk, 'disk', 25);
        $folder = $this->nullableString($row->folder, 'folder', 255);
        $hash = $this->filename($row->hash, 'hash', 255);

        return [$disk, $folder, $hash, $this->path($source['root_folder'] ?? '', $folder ?? '')];
    }

    private function path(string ...$parts): string
    {
        $path = implode('/', array_filter($parts, static fn (string $part): bool => $part !== ''));
        MediaPathResolver::assertSafe($path);
        if (str_starts_with($path, '/') || str_contains($path, '//') || str_contains($path, '\\')) {
            throw new LogicException('has an invalid legacy object path.');
        }

        return $path;
    }

    private function requireFile(string $disk, string $path): void
    {
        if (! $this->existence->existsFresh($disk, $path)) {
            throw new LogicException("has no backing file [{$disk}:{$path}].");
        }
    }

    private function uuid(mixed $id): string
    {
        if (! is_string($id) || ! Str::isUuid($id) || strtolower($id) !== $id) {
            throw new LogicException('requires a canonical UUID.');
        }

        return $id;
    }

    private function string(mixed $value, string $field, int $limit): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $limit) {
            throw new LogicException("has an invalid [{$field}] value.");
        }

        return $value;
    }

    private function nullableString(mixed $value, string $field, int $limit): ?string
    {
        return $value === null || $value === '' ? $value : $this->string($value, $field, $limit);
    }

    private function filename(mixed $value, string $field, int $limit): string
    {
        $value = $this->string($value, $field, $limit);
        if (strpbrk(rawurldecode($value), '/\\') !== false || $value === '.') {
            throw new LogicException("has an invalid [{$field}] filename segment.");
        }

        return $value;
    }

    private function number(mixed $value, int $maximum = PHP_INT_MAX): int
    {
        $integer = (is_string($value) || is_int($value))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maximum]])
            : false;
        if ($integer === false) {
            throw new LogicException('has an out-of-range integer.');
        }

        return $integer;
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new LogicException('has an invalid legacy JSON value.');
        }
        json_decode($value, flags: JSON_THROW_ON_ERROR);

        return $value;
    }
}
