<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Contracts\RecoverableMultipartUploadGateway;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\ImageFormat;
use Nvl\Media\Enums\MediaImageDriver;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Support\MediaDiskResolver;
use Nvl\Media\Support\MediaImageConfiguration;
use Nvl\Media\Support\MediaQueueConfiguration;
use Nvl\Support\Config\PackageOptions;
use Throwable;

/**
 * Performs read-only production-readiness diagnostics for Media.
 *
 * @internal
 */
final readonly class MediaDoctor
{
    public function __construct(
        private Container $container,
        private MediaFileExistence $existence,
    ) {}

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    public function inspect(bool $production = false): array
    {
        return [
            $this->permissionAdoptionCheck(),
            $this->s3DependencyCheck(),
            ...$this->schemaChecks(),
            $this->migrationOwnershipCheck(),
            ...$this->diskChecks($production),
            $this->storageConfigurationCheck(),
            $this->representativeStoragePathCheck($production),
            ...$this->integrityChecks($production),
            ...$this->imageChecks(),
            ...$this->queueChecks($production),
            ...$this->sourceChecks($production),
            ...$this->lockChecks($production),
            ...$this->ownerSlotChecks(),
            ...$this->bindingChecks($production),
            ...$this->multipartChecks($production),
        ];
    }

    /**
     * Validate visibility disks and per-disk prefixes without creating files or adapters.
     *
     * @return array{key: string, severity: string, passed: bool, message: string} Machine-readable storage configuration result
     */
    private function storageConfigurationCheck(): array
    {
        try {
            $guard = new MediaDiskGuard;
            foreach ([true, false] as $isPublic) {
                $disk = MediaDiskResolver::resolve(null, $isPublic);
                if (! $guard->isAllowed($disk) || config("filesystems.disks.{$disk}") === null) {
                    throw new InvalidArgumentException("Visibility disk [{$disk}] must be defined and allowlisted.");
                }
                MediaPathResolver::rootFolder($disk);
            }
            $roots = config('nvl-media.root_folders', []);
            if (! is_array($roots)) {
                throw new InvalidArgumentException('nvl-media.root_folders must be a disk-to-folder map.');
            }
            foreach ($roots as $disk => $root) {
                if (! is_string($disk) || $disk === '' || config("filesystems.disks.{$disk}") === null) {
                    throw new InvalidArgumentException('Every root_folders key must name a defined filesystem disk.');
                }
                MediaPathResolver::rootFolder($disk);
            }

            return ['key' => 'storage.configuration', 'severity' => 'error', 'passed' => true, 'message' => 'Visibility disks and disk-relative media roots are valid.'];
        } catch (InvalidArgumentException|MediaUploadException $exception) {
            return ['key' => 'storage.configuration', 'severity' => 'error', 'passed' => false, 'message' => $exception->getMessage()];
        }
    }

    /** Report optional S3 readiness from declared feature selection without resolving an adapter. */
    /**
     * @return array{key: string, severity: string, passed: bool, message: string}
     */
    private function s3DependencyCheck(): array
    {
        $disks = config('nvl-media.allowed_disks', []);
        $selected = false;
        foreach (is_array($disks) ? $disks : [] as $disk) {
            if (is_string($disk) && config("filesystems.disks.{$disk}.driver") === 's3') {
                $selected = true;
                break;
            }
        }
        $available = (new MediaS3DependencyGuard)->available();

        return ['key' => 'dependencies.s3', 'severity' => $selected ? 'error' : 'info', 'passed' => ! $selected || $available, 'message' => $selected
                ? ($available ? 'The selected built-in S3 dependencies are available.' : 'Install league/flysystem-aws-s3-v3:^3.0 and aws/aws-sdk-php:^3.322.9 before selecting built-in S3 storage.')
                : 'Built-in S3 storage is inactive; its SDK and adapter are optional.'];
    }

    /** Validate the opt-in bridge and report only explicitly selected permission names. */
    /**
     * @return array{key: string, severity: string, passed: bool, message: string}
     */
    private function permissionAdoptionCheck(): array
    {
        $enabled = config('nvl-media.authorization.spatie_permission.enabled', false);
        if (! is_bool($enabled)) {
            return ['key' => 'adoption.spatie_permission', 'severity' => 'error', 'passed' => false, 'message' => 'nvl-media.authorization.spatie_permission.enabled must be boolean.'];
        }
        if (! $enabled) {
            return ['key' => 'adoption.spatie_permission', 'severity' => 'info', 'passed' => true, 'message' => 'Spatie permission bridge is disabled; package ownership authorization applies.'];
        }
        $permission = config('nvl-media.authorization.spatie_permission.global_permission');
        if (! is_string($permission) || trim($permission) === '') {
            return ['key' => 'adoption.spatie_permission', 'severity' => 'error', 'passed' => false, 'message' => 'The explicit Spatie permission bridge requires a nonempty global permission name.'];
        }

        return ['key' => 'adoption.spatie_permission', 'severity' => 'info', 'passed' => true, 'message' => 'Explicit Spatie permission bridge is active; global permission: '.$permission.'. Only deliberately configured ability mappings grant cross-owner access.'];
    }

    /**
     * Verify the opt-in ownership installation without requiring it in disabled deployments.
     *
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */

    /**
     * Warn when automatic vendor loading overlaps a published migration copy.
     *
     * @return array{key: string, severity: string, passed: bool, message: string}
     */
    private function migrationOwnershipCheck(): array
    {
        $duplicates = config('nvl-media.migrations.enabled') === true
            ? $this->publishedMigrationDuplicates(dirname(__DIR__, 2).'/database/migrations')
            : [];

        return ['key' => 'migrations.ownership', 'severity' => 'warning', 'passed' => $duplicates === [], 'message' => $duplicates === []
                ? 'Automatic vendor migration loading does not overlap a published host copy.'
                : sprintf(
                    'Automatic vendor migration loading overlaps published host migration(s): %s. Disable media.migrations.enabled before running host-owned copies.',
                    implode(', ', $duplicates),
                )];
    }

    /**
     * Find host migrations whose timestamp-independent names match package migrations.
     *
     * @return list<string>
     */
    private function publishedMigrationDuplicates(string $packagePath): array
    {
        $packageMigrations = glob($packagePath.'/*.php') ?: [];
        $hostMigrations = glob(database_path('migrations/*.php')) ?: [];
        $packageNames = array_map($this->migrationName(...), $packageMigrations);
        $duplicates = [];

        foreach ($hostMigrations as $migration) {
            $name = $this->migrationName($migration);

            if (in_array($name, $packageNames, true)) {
                $duplicates[] = $name;
            }
        }

        sort($duplicates);

        return array_values(array_unique($duplicates));
    }

    /**
     * Remove Laravel's timestamp prefix from a migration filename.
     */
    private function migrationName(string $path): string
    {
        return (string) preg_replace(
            '/^\d{4}_\d{2}_\d{2}_\d{6}_/',
            '',
            pathinfo($path, PATHINFO_FILENAME),
        );
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function integrityChecks(bool $production): array
    {
        $verification = config('nvl-media.integrity.verification');
        $providerChecksum = config('nvl-media.integrity.provider_checksum');
        $readback = config('nvl-media.integrity.streamed_readback_fallback');
        $safe = $verification === 'required'
            && in_array($providerChecksum, ['prefer', 'ignore'], true)
            && $readback === true;

        return [['key' => 'integrity.policy', 'severity' => $production ? 'error' : 'warning', 'passed' => $safe, 'message' => $safe
                ? 'Stored objects require size/checksum verification with streamed fallback.'
                : 'Media integrity must remain required with streamed readback fallback enabled.']];
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function ownerSlotChecks(): array
    {
        $processingTimeout = config(
            'nvl-media.owner_slots.idempotency.processing_timeout_minutes',
        );
        $retentionDays = config('nvl-media.owner_slots.idempotency.retention_days');
        $pruneChunk = config('nvl-media.owner_slots.idempotency.prune_chunk');
        $valid = is_int($processingTimeout)
            && $processingTimeout >= 1
            && $processingTimeout <= 1_440
            && is_int($retentionDays)
            && $retentionDays >= 1
            && is_int($pruneChunk)
            && $pruneChunk >= 1
            && $pruneChunk <= 1_000;

        return [
            ['key' => 'owner_slots.idempotency.bounds', 'severity' => 'error', 'passed' => $valid, 'message' => $valid
                    ? 'Owner-slot processing, retention, and pruning bounds are valid.'
                    : 'Owner-slot processing timeout must be 1-1440 minutes, retention must be positive, and prune chunk must be 1-1000.'],
            $this->ownerSlotRegistrationCheck(),
        ];
    }

    /**
     * Validate the bounded set of owner type and slot pairs observed by the ledger.
     *
     * @return array{key: string, severity: string, passed: bool, message: string}
     */
    private function ownerSlotRegistrationCheck(): array
    {
        try {
            $registrations = MediaOwnerSlotOperation::query()
                ->select(['owner_type', 'slot'])
                ->distinct()
                ->orderBy('owner_type')
                ->orderBy('slot')
                ->limit(101)
                ->get();

            if ($registrations->count() > 100) {
                return ['key' => 'owner_slots.registrations', 'severity' => 'error', 'passed' => false, 'message' => 'Owner-slot diagnostics support at most 100 observed owner type and slot pairs.'];
            }

            $invalid = [];

            foreach ($registrations as $registration) {
                $ownerType = $registration->owner_type;
                $slotName = $registration->slot;
                $ownerClass = Relation::getMorphedModel($ownerType) ?? $ownerType;

                if (! class_exists($ownerClass)
                    || ! is_subclass_of($ownerClass, Model::class)
                    || ! is_subclass_of($ownerClass, HasNvlMedia::class)) {
                    $invalid[] = "{$ownerType}:{$slotName} (owner model is unavailable)";

                    continue;
                }

                $owner = new $ownerClass;
                $slot = MediaRegistration::forOwner($owner)->getSlot($slotName);

                if (! $slot instanceof MediaSlot
                    || ! $slot->isSingleFile
                    || $slot->slotSizeLimit !== 1) {
                    $invalid[] = "{$ownerType}:{$slotName} (registered single-file slot is missing)";
                }
            }

            return ['key' => 'owner_slots.registrations', 'severity' => 'error', 'passed' => $invalid === [], 'message' => $invalid === []
                    ? 'Observed owner-slot registrations resolve to single-file model slots.'
                    : 'Invalid observed owner-slot registrations: '.implode(', ', $invalid)];
        } catch (Throwable $exception) {
            return ['key' => 'owner_slots.registrations', 'severity' => 'error', 'passed' => false, 'message' => 'Owner-slot registration inspection failed: '.mb_substr(
                $exception->getMessage(),
                0,
                500,
            )];
        }
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function schemaChecks(): array
    {
        $checks = [];

        try {
            $ownerSlotOperationsTable = MediaConfiguration::ownerSlotOperationTable();
            $requirements = [
                MediaTables::get(MediaTables::Media) => [
                    'id',
                    'digest',
                    'disk',
                    'visibility',
                    'status',
                    'uploaded_by',
                    'uploaded_by_type',
                    'upload_session_id',
                    'revision',
                    'variation_definitions',
                ],
                MediaTables::get(MediaTables::Associations) => [
                    'media_id',
                    'associable_type',
                    'associable_id',
                    'collection',
                    'is_active',
                ],
                MediaTables::get(MediaTables::ImageVariations) => [
                    'media_id',
                    'label',
                    'status',
                    'source_revision',
                    'storage_path',
                ],
                MediaTables::get(MediaTables::I18n) => [
                    'media_id',
                    'locale',
                    'title',
                    'alt',
                    'caption',
                    'description',
                ],
                MediaTables::get(MediaTables::MultipartUploads) => [
                    'id',
                    'provider_state',
                    'object_key',
                    'object_key_hash',
                    'expected_size',
                    'expected_checksum',
                    'status',
                    'expires_at',
                    'completed_media_id',
                ],
                $ownerSlotOperationsTable => [
                    'id',
                    'idempotency_key',
                    'actor_type',
                    'actor_id',
                    'owner_type',
                    'owner_id',
                    'slot',
                    'operation',
                    'request_hash',
                    'status',
                    'result_media_id',
                    'result_payload',
                    'failure_code',
                    'completed_at',
                    'failed_at',
                    'created_at',
                    'updated_at',
                ],
            ];
            $connections = [
                $ownerSlotOperationsTable => MediaConfiguration::ownerSlotOperationConnection(),
            ];

            foreach ($requirements as $table => $columns) {
                $schema = Schema::connection($connections[$table] ?? null);
                $exists = $schema->hasTable($table);
                $checks[] = ['key' => "schema.table.{$table}", 'severity' => 'error', 'passed' => $exists, 'message' => $exists ? "Table [{$table}] is available." : "Table [{$table}] is missing."];

                if (! $exists) {
                    continue;
                }

                foreach ($columns as $column) {
                    $present = $schema->hasColumn($table, $column);
                    $checks[] = ['key' => "schema.column.{$table}.{$column}", 'severity' => 'error', 'passed' => $present, 'message' => $present
                            ? "Column [{$table}.{$column}] is available."
                            : "Column [{$table}.{$column}] is missing."];
                }

                $indexes = $schema->getIndexes($table);

                foreach ($this->requiredIndexes($table) as $index) {
                    $present = collect($indexes)->contains(
                        static fn (array $definition): bool => $definition['name'] === $index,
                    );
                    $checks[] = ['key' => "schema.index.{$table}.{$index}", 'severity' => 'error', 'passed' => $present, 'message' => $present
                            ? "Index [{$index}] is available on [{$table}]."
                            : "Index [{$index}] is missing from [{$table}]."];
                }
            }
        } catch (Throwable $exception) {
            $checks[] = ['key' => 'schema.connection', 'severity' => 'error', 'passed' => false, 'message' => 'Database inspection failed: '.mb_substr($exception->getMessage(), 0, 500)];
        }

        return $checks;
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function diskChecks(bool $production): array
    {
        $configured = config('nvl-media.allowed_disks', []);
        $disks = is_array($configured) ? $configured : [];

        if ($disks === []) {
            return [['key' => 'storage.allowed_disks', 'severity' => 'error', 'passed' => false, 'message' => 'No media storage disk is allowlisted.']];
        }

        $checks = [];

        foreach ($disks as $disk) {
            $name = is_string($disk) ? $disk : '';
            $defined = $name !== '' && config("filesystems.disks.{$name}") !== null;
            $checks[] = ['key' => 'storage.disk.'.($name !== '' ? $name : 'invalid'), 'severity' => 'error', 'passed' => $defined, 'message' => $defined
                    ? "Media disk [{$name}] is configured."
                    : "Media disk [{$name}] is not configured."];

            if (! $defined) {
                continue;
            }

            $driver = config("filesystems.disks.{$name}.driver");
            $throws = config("filesystems.disks.{$name}.throw") === true;
            $checks[] = ['key' => "storage.{$name}.throw", 'severity' => $production ? 'error' : 'warning', 'passed' => ! $production || $throws, 'message' => $throws
                    ? "Media disk [{$name}] throws write exceptions."
                    : "Set media disk [{$name}] throw option to true."];
            if ($driver !== 's3') {
                continue;
            }

            $bucket = config("filesystems.disks.{$name}.bucket");
            $checks[] = ['key' => "storage.s3.{$name}.adapter", 'severity' => 'error', 'passed' => class_exists(AwsS3V3Adapter::class), 'message' => class_exists(AwsS3V3Adapter::class)
                    ? 'The S3 Flysystem adapter is installed.'
                    : 'S3 is active but league/flysystem-aws-s3-v3 is unavailable.'];
            $checks[] = ['key' => "storage.s3.{$name}.bucket", 'severity' => 'error', 'passed' => is_string($bucket) && $bucket !== '', 'message' => is_string($bucket) && $bucket !== ''
                    ? "S3 disk [{$name}] has a bucket configured."
                    : "S3 disk [{$name}] requires a non-empty bucket."];
        }

        return $checks;
    }

    /**
     * Verify representative live media paths against the configured root folder.
     *
     * @return array{key: string, severity: string, passed: bool, message: string}
     */
    private function representativeStoragePathCheck(bool $production): array
    {
        if (! Schema::hasTable(MediaTables::get(MediaTables::Media))) {
            return ['key' => 'storage.persisted_paths', 'severity' => $production ? 'error' : 'warning', 'passed' => true, 'message' => 'Persisted media paths are unavailable until the media table exists.'];
        }

        $limit = MediaConfiguration::integer('nvl-media.adoption.path_sample_size', 25, 1);
        $columns = ['id', 'disk', 'folder', 'hash'];

        if (Schema::hasColumn(MediaTables::get(MediaTables::Media), 'storage_path')) {
            $columns[] = 'storage_path';
        }

        try {
            $samples = MediaModelRegistry::query()
                ->orderBy('id')
                ->limit($limit)
                ->get($columns);
        } catch (Throwable $exception) {
            return ['key' => 'storage.persisted_paths', 'severity' => $production ? 'error' : 'warning', 'passed' => false, 'message' => 'Persisted media paths cannot be inspected: '.mb_substr($exception->getMessage(), 0, 500)];
        }

        if ($samples->isEmpty()) {
            return ['key' => 'storage.persisted_paths', 'severity' => $production ? 'error' : 'warning', 'passed' => true, 'message' => 'No live persisted media rows require representative path verification.'];
        }

        $missing = [];

        foreach ($samples as $media) {
            $path = null;
            try {
                $path = $media->buildPath();
                if (! $this->existence->existsFresh($media->disk, $path)) {
                    $missing[] = $media->disk.':'.$path;
                }
            } catch (Throwable $exception) {
                $missing[] = $media->disk.':'.($path ?? 'media-'.$media->id).' ('.$exception->getMessage().')';
            }
        }

        $passed = $missing === [];
        try {
            $roots = [];
            foreach ($samples as $media) {
                $root = MediaPathResolver::rootFolder($media->disk);
                $roots[$media->disk] = $media->disk.'='.($root === '' ? '<empty>' : $root);
            }
            $rootLabel = implode(', ', $roots);
        } catch (InvalidArgumentException|MediaUploadException $exception) {
            return ['key' => 'storage.persisted_paths', 'severity' => 'error', 'passed' => false, 'message' => $exception->getMessage()];
        }

        return ['key' => 'storage.persisted_paths', 'severity' => $production ? 'error' : 'warning', 'passed' => $passed, 'message' => $passed
                ? "Representative live media paths exist with root_folder [{$rootLabel}]."
                : sprintf(
                    'Representative live persisted paths are missing with root_folder [%s]: %s. Existing complete folders require an empty media.root_folder, or move the objects through nvl:media:migrate-disk before cutover.',
                    $rootLabel,
                    implode(', ', array_slice($missing, 0, 5)),
                )];
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function imageChecks(): array
    {
        try {
            $driver = MediaImageDriver::resolve(config('nvl-media.image_driver', MediaImageDriver::Gd));
            $driverAvailable = match ($driver) {
                MediaImageDriver::Gd => extension_loaded('gd'),
                MediaImageDriver::Imagick => extension_loaded('imagick'),
                MediaImageDriver::Vips => class_exists('Jcupitt\\Vips\\Image'),
            };
            $checks = [['key' => 'images.driver', 'severity' => 'error', 'passed' => $driverAvailable, 'message' => $driverAvailable
                    ? "Image driver [{$driver->value}] is available."
                    : "Configured image driver [{$driver->value}] is unavailable."]];

            if (! $driverAvailable) {
                return $checks;
            }

            /** @var array<string, ImageFormat> $formats */
            $formats = [];

            foreach (MediaImageConfiguration::presets() as $preset) {
                if (isset($preset['format']) && is_string($preset['format'])) {
                    $format = ImageFormat::resolve($preset['format']);
                    $formats[$format->value] = $format;
                }
            }

            $output = MediaImageConfiguration::outputConversion('jpg');

            if (isset($output['format']) && is_string($output['format'])) {
                $format = ImageFormat::resolve($output['format']);
                $formats[$format->value] = $format;
            }

            foreach ($formats as $format) {
                $available = $this->encoderAvailable($driver, $format);
                $checks[] = ['key' => "images.encoder.{$format->value}", 'severity' => $available === null ? 'warning' : 'error', 'passed' => $available !== false, 'message' => $available === true
                        ? "The [{$driver->value}] driver can encode {$format->value}."
                        : ($available === false
                            ? "The [{$driver->value}] driver cannot encode {$format->value}."
                            : "Run a smoke conversion to verify the {$format->value} codec.")];
            }

            return $checks;
        } catch (Throwable $exception) {
            return [['key' => 'images.configuration', 'severity' => 'error', 'passed' => false, 'message' => 'Image configuration is invalid: '.mb_substr($exception->getMessage(), 0, 500)]];
        }
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function queueChecks(bool $production): array
    {
        $connection = MediaQueueConfiguration::connection();
        $definition = config("queue.connections.{$connection}");
        $defined = is_array($definition);
        $backgroundEnabled = MediaQueueConfiguration::enabled();
        $durable = ! $production || ! $backgroundEnabled || $connection !== 'sync';
        $checks = [
            ['key' => 'queue.connection', 'severity' => 'error', 'passed' => $defined, 'message' => $defined
                    ? "Media queue connection [{$connection}] is configured."
                    : "Media queue connection [{$connection}] is not configured."],
            ['key' => 'queue.durable', 'severity' => $production ? 'error' : 'warning', 'passed' => $durable, 'message' => $durable
                    ? 'Background media work uses a durable queue or is disabled.'
                    : 'Background media work requires a non-sync queue in production.'],
        ];

        if (! $defined || ! $backgroundEnabled || $connection === 'sync') {
            return $checks;
        }

        $driver = $definition['driver'] ?? null;
        $maximumTimeout = max(
            MediaQueueConfiguration::jobInteger('generate', 'timeout', 60),
            MediaQueueConfiguration::jobInteger('dispatch', 'timeout', 60),
            MediaQueueConfiguration::jobInteger('regenerate', 'timeout', 60),
        );

        if ($driver === 'sqs') {
            $visibilityTimeout = $definition['visibility_timeout'] ?? null;
            $safe = is_int($visibilityTimeout) && $visibilityTimeout > $maximumTimeout;
            $checks[] = ['key' => 'queue.visibility_timeout', 'severity' => 'error', 'passed' => $safe, 'message' => $safe
                    ? 'SQS visibility timeout exceeds every Media job timeout.'
                    : "SQS visibility_timeout must exceed {$maximumTimeout} seconds."];
        } else {
            $retryAfter = $definition['retry_after'] ?? null;
            $safe = is_int($retryAfter) && $retryAfter > $maximumTimeout;
            $checks[] = ['key' => 'queue.retry_after', 'severity' => 'error', 'passed' => $safe, 'message' => $safe
                    ? 'Queue retry_after exceeds every Media job timeout.'
                    : "Queue retry_after must exceed {$maximumTimeout} seconds."];
        }

        return $checks;
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function sourceChecks(bool $production): array
    {
        $enabled = (bool) config('nvl-media.sources.remote.enabled', false);
        $ports = MediaConfiguration::integerList(
            'nvl-media.sources.remote.allowed_ports',
            [80, 443],
        );
        $safePorts = $ports !== [] && collect($ports)->every(
            static fn (int $port): bool => $port >= 1 && $port <= 65535,
        );
        $connectTimeout = MediaConfiguration::integer(
            'nvl-media.sources.remote.connect_timeout',
            0,
            1,
        );
        $totalTimeout = MediaConfiguration::integer(
            'nvl-media.sources.remote.total_timeout',
            0,
            1,
        );
        $redirects = config('nvl-media.sources.remote.redirects');
        $maximumBytes = config('nvl-media.sources.remote.maximum_bytes');
        $verifyConnectedIp = config('nvl-media.sources.remote.verify_connected_ip');
        $boundsSafe = $connectTimeout > 0
            && $totalTimeout >= $connectTimeout
            && is_int($redirects)
            && $redirects >= 0
            && is_int($maximumBytes)
            && $maximumBytes > 0
            && $verifyConnectedIp === true;

        return [
            ['key' => 'sources.remote.curl', 'severity' => $production ? 'error' : 'warning', 'passed' => ! $enabled || extension_loaded('curl'), 'message' => ! $enabled || extension_loaded('curl')
                    ? 'Remote media cURL requirement is satisfied.'
                    : 'Remote media sources require ext-curl.'],
            ['key' => 'sources.remote.configuration', 'severity' => $production ? 'error' : 'warning', 'passed' => ! $enabled || ($safePorts && $boundsSafe), 'message' => ! $enabled || ($safePorts && $boundsSafe)
                    ? 'Remote media bounds are configured.'
                    : 'Remote media ports, redirects, byte limit, timeouts, and connected-IP attestation must be safe.'],
        ];
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function lockChecks(bool $production): array
    {
        $multiNode = (bool) config('nvl-media.deployment.multi_node', false);
        $atomic = false;
        $central = false;

        try {
            $storeName = PackageOptions::lockStore('media', 'mutation');
            $driver = config("cache.stores.{$storeName}.driver");
            $central = in_array($driver, ['redis', 'memcached', 'dynamodb'], true);
            $repository = Cache::store($storeName);
            $atomic = $repository->getStore() instanceof LockProvider;
        } catch (Throwable) {
            $atomic = false;
        }

        return [
            ['key' => 'locks.mutation.central', 'severity' => $production && $multiNode ? 'error' : 'warning', 'passed' => ! $multiNode || $central, 'message' => ! $multiNode || $central
                    ? 'Media mutation lock topology matches deployment mode.'
                    : 'Multi-node media deployments require a central atomic lock store.'],
            ['key' => 'locks.mutation.atomic', 'severity' => 'error', 'passed' => $atomic, 'message' => $atomic
                    ? 'The configured Media mutation store supports atomic locks.'
                    : 'The configured Media mutation store must implement atomic locks.'],
        ];
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function bindingChecks(bool $production): array
    {
        $scanner = $this->resolve(MediaContentScanner::class);
        $authorization = $this->resolve(MediaAuthorization::class);
        $acceptsUntrustedUploads = (bool) config('nvl-media.scanner.untrusted_uploads', true);
        $scannerSafe = $production
            ? (! $acceptsUntrustedUploads || ! $scanner instanceof NullMediaContentScanner)
            : (! (bool) config('nvl-media.scanner.required', false)
                || ! $scanner instanceof NullMediaContentScanner
                || (bool) config('nvl-media.scanner.allow_noop', false));

        return [
            ['key' => 'scanner.required', 'severity' => 'error', 'passed' => ! (bool) config('nvl-media.scanner.required', false) || ($scanner instanceof MediaContentScanner && ! $scanner instanceof NullMediaContentScanner), 'message' => 'Required scanner readiness needs a real scanner in every environment; allow_noop is a development bypass only.'],
            ['key' => 'binding.authorization', 'severity' => 'error', 'passed' => $authorization instanceof MediaAuthorization, 'message' => 'MediaAuthorization is bound.'],
            ['key' => 'binding.scanner', 'severity' => 'error', 'passed' => $scanner instanceof MediaContentScanner, 'message' => 'MediaContentScanner is bound.'],
            ['key' => 'scanner.production', 'severity' => $production ? 'error' : 'warning', 'passed' => $scannerSafe, 'message' => $scannerSafe
                    ? 'Media scanner policy is safe for enabled ingestion paths.'
                    : 'Untrusted uploads are enabled but only the no-op scanner is configured.'],
        ];
    }

    /**
     * @return list<array{key: string, severity: string, passed: bool, message: string}>
     */
    private function multipartChecks(bool $production): array
    {
        if (! (bool) config('nvl-media.multipart.enabled', false)) {
            return [['key' => 'multipart.disabled', 'severity' => 'warning', 'passed' => true, 'message' => 'Multipart uploads are disabled; server-proxied ingestion remains available.']];
        }

        $gateway = $this->resolve(MultipartUploadGateway::class);
        $scanner = $this->resolve(MediaContentScanner::class);
        $storeName = PackageOptions::lockStore('media', 'multipart');
        $driver = config("cache.stores.{$storeName}.driver");
        $centralLock = in_array($driver, ['redis', 'memcached', 'dynamodb'], true);

        return [
            ['key' => 'multipart.gateway.recoverable', 'severity' => $production ? 'error' : 'warning', 'passed' => $gateway instanceof RecoverableMultipartUploadGateway, 'message' => $gateway instanceof RecoverableMultipartUploadGateway
                    ? 'Multipart gateway supports completion recovery.'
                    : 'Enabled multipart uploads require a recoverable gateway.'],
            ['key' => 'multipart.lock.central', 'severity' => $production ? 'error' : 'warning', 'passed' => $centralLock, 'message' => $centralLock
                    ? 'Multipart transitions use a central lock store.'
                    : 'Enabled multipart uploads require a central lock store.'],
            ['key' => 'multipart.scanner.attestation', 'severity' => $production ? 'error' : 'warning', 'passed' => ! $scanner instanceof NullMediaContentScanner
                    && (bool) config('nvl-media.multipart.required_scan', true), 'message' => 'Enabled multipart uploads require scanner attestation.'],
        ];
    }

    private function encoderAvailable(
        MediaImageDriver $driver,
        ImageFormat $format,
    ): ?bool {
        if ($driver === MediaImageDriver::Imagick) {
            $formatName = $format === ImageFormat::Jpeg ? 'JPEG' : mb_strtoupper($format->value);
            $available = call_user_func(['Imagick', 'queryFormats'], $formatName);

            return $available !== [];
        }

        if ($driver === MediaImageDriver::Vips) {
            return null;
        }

        return match ($format) {
            ImageFormat::Webp => function_exists('imagewebp'),
            ImageFormat::Avif => function_exists('imageavif'),
            ImageFormat::Jpeg => function_exists('imagejpeg'),
            ImageFormat::Png => function_exists('imagepng'),
            ImageFormat::Gif => function_exists('imagegif'),
        };
    }

    /**
     * @return list<string>
     */
    private function requiredIndexes(string $table): array
    {
        if ($table === MediaConfiguration::ownerSlotOperationTable()) {
            return [
                'media_owner_slot_idempotency_unique',
                'media_owner_slot_owner_slot_idx',
                'media_owner_slot_created_idx',
            ];
        }

        return match ($table) {
            MediaTables::get(MediaTables::Media) => [
                'media_hash_idx',
                'media_visibility_created_idx',
                'media_uploader_created_idx',
                'media_disk_created_idx',
                'media_type_created_idx',
                'media_status_created_idx',
            ],
            MediaTables::get(MediaTables::MultipartUploads) => [
                'media_multipart_disk_object_hash_unique',
                'media_multipart_actor_created_idx',
                'media_multipart_status_expiry_idx',
                'media_multipart_completed_media_unique',
            ],
            default => [],
        };
    }

    private function resolve(string $abstract): ?object
    {
        try {
            $resolved = $this->container->make($abstract);

            return is_object($resolved) ? $resolved : null;
        } catch (Throwable) {
            return null;
        }
    }
}
