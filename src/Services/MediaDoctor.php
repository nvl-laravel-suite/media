<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Contracts\RecoverableMultipartUploadGateway;
use Nvl\Media\Data\MediaDoctorCheckData;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\ImageFormat;
use Nvl\Media\Enums\MediaImageDriver;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Support\MediaImageConfiguration;
use Nvl\Media\Support\MediaQueueConfiguration;
use Throwable;

/**
 * Performs read-only production-readiness diagnostics for Media.
 */
final readonly class MediaDoctor
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * @return list<MediaDoctorCheckData>
     */
    public function inspect(bool $production = false): array
    {
        return [
            ...$this->schemaChecks(),
            ...$this->diskChecks($production),
            ...$this->integrityChecks($production),
            ...$this->imageChecks(),
            ...$this->queueChecks($production),
            ...$this->routeChecks(),
            ...$this->sourceChecks($production),
            ...$this->lockChecks($production),
            ...$this->bindingChecks($production),
            ...$this->multipartChecks($production),
        ];
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function integrityChecks(bool $production): array
    {
        $verification = config('media.integrity.verification');
        $providerChecksum = config('media.integrity.provider_checksum');
        $readback = config('media.integrity.streamed_readback_fallback');
        $safe = $verification === 'required'
            && in_array($providerChecksum, ['prefer', 'ignore'], true)
            && $readback === true;

        return [new MediaDoctorCheckData(
            'integrity.policy',
            $production ? 'error' : 'warning',
            $safe,
            $safe
                ? 'Stored objects require size/checksum verification with streamed fallback.'
                : 'Media integrity must remain required with streamed readback fallback enabled.',
        )];
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function schemaChecks(): array
    {
        $requirements = [
            MediaTables::MEDIA => [
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
            MediaTables::MEDIA_ASSOCIATIONS => [
                'media_id',
                'associable_type',
                'associable_id',
                'collection',
                'is_active',
            ],
            MediaTables::MEDIA_IMAGE_VARIATIONS => [
                'media_id',
                'label',
                'status',
                'source_revision',
                'storage_path',
            ],
            MediaTables::MEDIA_I18N => [
                'media_id',
                'locale',
                'title',
                'alt',
                'caption',
                'description',
            ],
            MediaTables::MEDIA_MULTIPART_UPLOADS => [
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
        ];
        $checks = [];

        try {
            foreach ($requirements as $table => $columns) {
                $exists = Schema::hasTable($table);
                $checks[] = new MediaDoctorCheckData(
                    "schema.table.{$table}",
                    'error',
                    $exists,
                    $exists ? "Table [{$table}] is available." : "Table [{$table}] is missing.",
                );

                if (! $exists) {
                    continue;
                }

                foreach ($columns as $column) {
                    $present = Schema::hasColumn($table, $column);
                    $checks[] = new MediaDoctorCheckData(
                        "schema.column.{$table}.{$column}",
                        'error',
                        $present,
                        $present
                            ? "Column [{$table}.{$column}] is available."
                            : "Column [{$table}.{$column}] is missing.",
                    );
                }

                $indexes = Schema::getIndexes($table);

                foreach ($this->requiredIndexes($table) as $index) {
                    $present = collect($indexes)->contains(
                        static fn (array $definition): bool => ($definition['name'] ?? null) === $index,
                    );
                    $checks[] = new MediaDoctorCheckData(
                        "schema.index.{$table}.{$index}",
                        'error',
                        $present,
                        $present
                            ? "Index [{$index}] is available on [{$table}]."
                            : "Index [{$index}] is missing from [{$table}].",
                    );
                }
            }
        } catch (Throwable $exception) {
            $checks[] = new MediaDoctorCheckData(
                'schema.connection',
                'error',
                false,
                'Database inspection failed: '.mb_substr($exception->getMessage(), 0, 500),
            );
        }

        return $checks;
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function diskChecks(bool $production): array
    {
        $configured = config('media.allowed_disks', []);
        $disks = is_array($configured) ? $configured : [];

        if ($disks === []) {
            return [new MediaDoctorCheckData(
                'storage.allowed_disks',
                'error',
                false,
                'No media storage disk is allowlisted.',
            )];
        }

        $checks = [];

        foreach ($disks as $disk) {
            $name = is_string($disk) ? $disk : '';
            $defined = $name !== '' && config("filesystems.disks.{$name}") !== null;
            $checks[] = new MediaDoctorCheckData(
                'storage.disk.'.($name !== '' ? $name : 'invalid'),
                'error',
                $defined,
                $defined
                    ? "Media disk [{$name}] is configured."
                    : "Media disk [{$name}] is not configured.",
            );

            if (! $defined) {
                continue;
            }

            $driver = config("filesystems.disks.{$name}.driver");
            $throws = config("filesystems.disks.{$name}.throw") === true;
            $checks[] = new MediaDoctorCheckData(
                "storage.{$name}.throw",
                $production ? 'error' : 'warning',
                ! $production || $throws,
                $throws
                    ? "Media disk [{$name}] throws write exceptions."
                    : "Set media disk [{$name}] throw option to true.",
            );
            $safePublicLocal = $driver !== 'local'
                || (bool) config('media.routes.assets_enabled', true);
            $checks[] = new MediaDoctorCheckData(
                "storage.{$name}.public_local_delivery",
                $production ? 'error' : 'warning',
                $safePublicLocal,
                $safePublicLocal
                    ? "Media disk [{$name}] uses controlled asset delivery."
                    : "Local media disk [{$name}] requires route-backed asset delivery.",
            );

            if ($driver !== 's3') {
                continue;
            }

            $bucket = config("filesystems.disks.{$name}.bucket");
            $checks[] = new MediaDoctorCheckData(
                "storage.s3.{$name}.adapter",
                'error',
                class_exists(AwsS3V3Adapter::class),
                class_exists(AwsS3V3Adapter::class)
                    ? 'The S3 Flysystem adapter is installed.'
                    : 'S3 is active but league/flysystem-aws-s3-v3 is unavailable.',
            );
            $checks[] = new MediaDoctorCheckData(
                "storage.s3.{$name}.bucket",
                'error',
                is_string($bucket) && $bucket !== '',
                is_string($bucket) && $bucket !== ''
                    ? "S3 disk [{$name}] has a bucket configured."
                    : "S3 disk [{$name}] requires a non-empty bucket.",
            );
        }

        return $checks;
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function imageChecks(): array
    {
        try {
            $driver = MediaImageDriver::resolve(config('media.image_driver', MediaImageDriver::Gd));
            $driverAvailable = match ($driver) {
                MediaImageDriver::Gd => extension_loaded('gd'),
                MediaImageDriver::Imagick => extension_loaded('imagick'),
                MediaImageDriver::Vips => class_exists('Jcupitt\\Vips\\Image'),
            };
            $checks = [new MediaDoctorCheckData(
                'images.driver',
                'error',
                $driverAvailable,
                $driverAvailable
                    ? "Image driver [{$driver->value}] is available."
                    : "Configured image driver [{$driver->value}] is unavailable.",
            )];

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
                $checks[] = new MediaDoctorCheckData(
                    "images.encoder.{$format->value}",
                    $available === null ? 'warning' : 'error',
                    $available !== false,
                    $available === true
                        ? "The [{$driver->value}] driver can encode {$format->value}."
                        : ($available === false
                            ? "The [{$driver->value}] driver cannot encode {$format->value}."
                            : "Run a smoke conversion to verify the {$format->value} codec."),
                );
            }

            return $checks;
        } catch (Throwable $exception) {
            return [new MediaDoctorCheckData(
                'images.configuration',
                'error',
                false,
                'Image configuration is invalid: '.mb_substr($exception->getMessage(), 0, 500),
            )];
        }
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function queueChecks(bool $production): array
    {
        $connection = MediaQueueConfiguration::connection();
        $definition = config("queue.connections.{$connection}");
        $defined = is_array($definition);
        $backgroundEnabled = MediaQueueConfiguration::enabled();
        $durable = ! $production || ! $backgroundEnabled || $connection !== 'sync';
        $checks = [
            new MediaDoctorCheckData(
                'queue.connection',
                'error',
                $defined,
                $defined
                    ? "Media queue connection [{$connection}] is configured."
                    : "Media queue connection [{$connection}] is not configured.",
            ),
            new MediaDoctorCheckData(
                'queue.durable',
                $production ? 'error' : 'warning',
                $durable,
                $durable
                    ? 'Background media work uses a durable queue or is disabled.'
                    : 'Background media work requires a non-sync queue in production.',
            ),
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
            $checks[] = new MediaDoctorCheckData(
                'queue.visibility_timeout',
                'error',
                $safe,
                $safe
                    ? 'SQS visibility timeout exceeds every Media job timeout.'
                    : "SQS visibility_timeout must exceed {$maximumTimeout} seconds.",
            );
        } else {
            $retryAfter = $definition['retry_after'] ?? null;
            $safe = is_int($retryAfter) && $retryAfter > $maximumTimeout;
            $checks[] = new MediaDoctorCheckData(
                'queue.retry_after',
                'error',
                $safe,
                $safe
                    ? 'Queue retry_after exceeds every Media job timeout.'
                    : "Queue retry_after must exceed {$maximumTimeout} seconds.",
            );
        }

        return $checks;
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function routeChecks(): array
    {
        $managementEnabled = (bool) config('media.routes.api_enabled', false);
        $middleware = config('media.routes.management_middleware', []);
        $middlewareProtected = ! $managementEnabled
            || (is_array($middleware) && in_array('auth', $middleware, true));

        return [
            new MediaDoctorCheckData(
                'routes.management.authorization',
                'error',
                $middlewareProtected,
                $middlewareProtected
                    ? 'Media management routes are disabled or protected by auth middleware.'
                    : 'Enabled Media management routes must include auth middleware.',
            ),
            new MediaDoctorCheckData(
                'routes.management.loaded',
                'error',
                ! $managementEnabled || Route::has('nvl.media.management.index'),
                'Media management route state matches configuration.',
            ),
            new MediaDoctorCheckData(
                'routes.assets.loaded',
                'error',
                ! (bool) config('media.routes.assets_enabled', true)
                    || Route::has('media.assets.show'),
                'Media asset route state was inspected.',
            ),
        ];
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function sourceChecks(bool $production): array
    {
        $enabled = (bool) config('media.sources.remote.enabled', false);
        $ports = MediaConfiguration::integerList(
            'media.sources.remote.allowed_ports',
            [80, 443],
        );
        $safePorts = $ports !== [] && collect($ports)->every(
            static fn (int $port): bool => $port >= 1 && $port <= 65535,
        );
        $connectTimeout = MediaConfiguration::integer(
            'media.sources.remote.connect_timeout',
            0,
            1,
        );
        $totalTimeout = MediaConfiguration::integer(
            'media.sources.remote.total_timeout',
            0,
            1,
        );
        $redirects = config('media.sources.remote.redirects');
        $maximumBytes = config('media.sources.remote.maximum_bytes');
        $verifyConnectedIp = config('media.sources.remote.verify_connected_ip');
        $boundsSafe = $connectTimeout > 0
            && $totalTimeout >= $connectTimeout
            && is_int($redirects)
            && $redirects >= 0
            && is_int($maximumBytes)
            && $maximumBytes > 0
            && $verifyConnectedIp === true;

        return [
            new MediaDoctorCheckData(
                'sources.remote.curl',
                $production ? 'error' : 'warning',
                ! $enabled || extension_loaded('curl'),
                ! $enabled || extension_loaded('curl')
                    ? 'Remote media cURL requirement is satisfied.'
                    : 'Remote media sources require ext-curl.',
            ),
            new MediaDoctorCheckData(
                'sources.remote.configuration',
                $production ? 'error' : 'warning',
                ! $enabled || ($safePorts && $boundsSafe),
                ! $enabled || ($safePorts && $boundsSafe)
                    ? 'Remote media bounds are configured.'
                    : 'Remote media ports, redirects, byte limit, timeouts, and connected-IP attestation must be safe.',
            ),
        ];
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function lockChecks(bool $production): array
    {
        $multiNode = (bool) config('media.deployment.multi_node', false);
        $storeName = config('media.mutation_lock.store') ?: config('cache.default');
        $driver = is_string($storeName)
            ? config("cache.stores.{$storeName}.driver")
            : null;
        $central = in_array($driver, ['redis', 'memcached', 'dynamodb'], true);

        return [new MediaDoctorCheckData(
            'locks.mutation.central',
            $production && $multiNode ? 'error' : 'warning',
            ! $multiNode || $central,
            ! $multiNode || $central
                ? 'Media mutation lock topology matches deployment mode.'
                : 'Multi-node media deployments require a central atomic lock store.',
        )];
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function bindingChecks(bool $production): array
    {
        $scanner = $this->resolve(MediaContentScanner::class);
        $authorization = $this->resolve(MediaAuthorization::class);
        $acceptsUntrustedUploads = (bool) config('media.scanner.untrusted_uploads', true);
        $scannerSafe = $production
            ? (! $acceptsUntrustedUploads || ! $scanner instanceof NullMediaContentScanner)
            : (! (bool) config('media.scanner.required', false)
                || ! $scanner instanceof NullMediaContentScanner
                || (bool) config('media.scanner.allow_noop', false));

        return [
            new MediaDoctorCheckData(
                'binding.authorization',
                'error',
                $authorization instanceof MediaAuthorization,
                'MediaAuthorization is bound.',
            ),
            new MediaDoctorCheckData(
                'binding.scanner',
                'error',
                $scanner instanceof MediaContentScanner,
                'MediaContentScanner is bound.',
            ),
            new MediaDoctorCheckData(
                'scanner.production',
                $production ? 'error' : 'warning',
                $scannerSafe,
                $scannerSafe
                    ? 'Media scanner policy is safe for enabled ingestion paths.'
                    : 'Untrusted uploads are enabled but only the no-op scanner is configured.',
            ),
        ];
    }

    /**
     * @return list<MediaDoctorCheckData>
     */
    private function multipartChecks(bool $production): array
    {
        if (! (bool) config('media.multipart.enabled', false)) {
            return [new MediaDoctorCheckData(
                'multipart.disabled',
                'warning',
                true,
                'Multipart uploads are disabled; server-proxied ingestion remains available.',
            )];
        }

        $gateway = $this->resolve(MultipartUploadGateway::class);
        $scanner = $this->resolve(MediaContentScanner::class);
        $storeName = config('media.multipart.lock.store') ?: config('cache.default');
        $driver = is_string($storeName)
            ? config("cache.stores.{$storeName}.driver")
            : null;
        $centralLock = in_array($driver, ['redis', 'memcached', 'dynamodb'], true);

        return [
            new MediaDoctorCheckData(
                'multipart.gateway.recoverable',
                $production ? 'error' : 'warning',
                $gateway instanceof RecoverableMultipartUploadGateway,
                $gateway instanceof RecoverableMultipartUploadGateway
                    ? 'Multipart gateway supports completion recovery.'
                    : 'Enabled multipart uploads require a recoverable gateway.',
            ),
            new MediaDoctorCheckData(
                'multipart.lock.central',
                $production ? 'error' : 'warning',
                $centralLock,
                $centralLock
                    ? 'Multipart transitions use a central lock store.'
                    : 'Enabled multipart uploads require a central lock store.',
            ),
            new MediaDoctorCheckData(
                'multipart.scanner.attestation',
                $production ? 'error' : 'warning',
                ! $scanner instanceof NullMediaContentScanner
                    && (bool) config('media.multipart.required_scan', true),
                'Enabled multipart uploads require scanner attestation.',
            ),
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
        return match ($table) {
            MediaTables::MEDIA => [
                'media_visibility_created_idx',
                'media_uploader_created_idx',
                'media_disk_created_idx',
                'media_type_created_idx',
                'media_status_created_idx',
            ],
            MediaTables::MEDIA_MULTIPART_UPLOADS => [
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
