<?php

declare(strict_types=1);

use Nvl\Media\Enums\ImageFit;
use Nvl\Media\Enums\ImagePreset;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\NullMediaContentScanner;
use Nvl\Media\Services\S3MultipartUploadGateway;

return [
    'models' => ['media' => Media::class],
    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    | The package keeps ownership checks as its standalone default. When the
    | authenticated model exposes Spatie Permission's role/permission methods,
    | these optional grants may authorize cross-owner operations. Global roles
    | are intentionally opt-in to avoid silently elevating a common role name.
    */
    'authorization' => ['spatie_permission' => ['enabled' => env('NVL_MEDIA_SPATIE_PERMISSION_ENABLED', false), 'global_roles' => array_values(array_filter(array_map(static fn (string $role): string => trim($role), explode(',', (string) env('NVL_MEDIA_GLOBAL_ROLES', ''))))), 'global_permission' => 'nvl.media.manage', 'ability_permissions' => ['list_all' => 'nvl.media.view-any', 'view' => 'nvl.media.view-any', 'download' => 'nvl.media.download-any', 'associate' => 'nvl.media.associate-any', 'mutate' => 'nvl.media.update-any', 'delete' => 'nvl.media.delete-any', 'reuse' => 'nvl.media.reuse-any', 'manage_staging' => 'nvl.media.manage-staging']]],
    'migrations' => ['enabled' => true],
    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    | Media can use a dedicated disk independent of the application default.
    | This keeps uploads portable to object storage without moving local-only
    | import/export/temp workflows that still rely on filesystems.default.
    |
    | MediaDiskResolver fallback chain:
    |   1. Explicit disk passed by the caller (collection or API)
    |   2. media.disk (MEDIA_FILESYSTEM_DISK)
    |   3. filesystems.default (application-level default)
    |   4. 'local' (hardcoded safety net — should never be reached)
    */
    'disk' => env('NVL_MEDIA_FILESYSTEM_DISK', env('FILESYSTEM_DISK', 'local')),
    // Optional visibility defaults; explicit upload and slot disk selections win.
    // Define and allowlist these disks in the host before selecting them.
    'public_disk' => null,
    'private_disk' => null,
    // An explicit empty string stores below the disk root without a base folder.
    'root_folders' => [],
    'sources' => ['remote' => [
        // URL ingestion is an explicit capability. Ordinary request, local,
        // disk, and base64 uploads do not incur its DNS/cURL boundary.
        'enabled' => env('NVL_MEDIA_REMOTE_SOURCES_ENABLED', false),
    ]],
    /*
    |--------------------------------------------------------------------------
    | Content / Malware Scanner
    |--------------------------------------------------------------------------
    | Configure an implementation of MediaContentScanner when accepting
    | untrusted documents or archives. The default performs no external scan.
    */
    'content_scanner' => NullMediaContentScanner::class,
    'scanner' => ['required' => env('NVL_MEDIA_SCANNER_REQUIRED', false), 'allow_noop' => env('NVL_MEDIA_ALLOW_NOOP_SCANNER', false), 'untrusted_uploads' => env('NVL_MEDIA_UNTRUSTED_UPLOADS', true)],
    'multipart' => [
        // Direct uploads are opt-in. Production diagnostics require a
        // recoverable gateway, scanner attestation, and a central lock store.
        'enabled' => env('NVL_MEDIA_MULTIPART_ENABLED', false),
        'gateway' => S3MultipartUploadGateway::class,
        'required_scan' => true,
        'lock' => ['seconds' => (int) env('NVL_MEDIA_MULTIPART_LOCK_SECONDS', 300)],
    ],
    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    | Production should use Redis, SQS, or another durable queue. A null
    | connection inherits Core's queue connection, then queue.default. Worker timeout
    | must remain below retry_after (or SQS visibility timeout).
    */
    'queue' => ['enabled' => env('NVL_MEDIA_QUEUE_ENABLED', true), 'connection' => env('NVL_MEDIA_QUEUE_CONNECTION'), 'name' => env('NVL_MEDIA_QUEUE'), 'jobs' => ['generate' => ['timeout' => 60, 'backoff' => [10, 30, 90], 'unique_for' => 1800], 'dispatch' => ['timeout' => 60, 'backoff' => [10, 30, 90], 'unique_for' => 1800], 'regenerate' => ['timeout' => 60, 'backoff' => [60]]]],
    /*
    |--------------------------------------------------------------------------
    | Shared Upload Deduplication Lock
    |--------------------------------------------------------------------------
    | Deduplicated shared uploads are serialized before file storage so two
    | concurrent requests cannot both create the same digest row. The default
    | cache store is the application database store unless overridden here.
    */
    'deduplication_lock' => ['enabled' => env('NVL_MEDIA_DEDUPLICATION_LOCK_ENABLED', true)],
    /*
    |--------------------------------------------------------------------------
    | Shared Media Mutation Lock
    |--------------------------------------------------------------------------
    | Replacement, movement, deletion, and variation mutations share this
    | lock. Multi-node production installations must select a central store.
    */
    'mutation_lock' => ['enabled' => env('NVL_MEDIA_MUTATION_LOCK_ENABLED', true)],
    /*
    |--------------------------------------------------------------------------
    | Image Variation Presets
    |--------------------------------------------------------------------------
    | These three defaults cover exact square previews and proportional cards.
    | Replace this array in the published config to change or add presets.
    | ImageFit::Max preserves aspect ratio and never upscales smaller images.
    */
    'image_variation_presets' => [ImagePreset::Thumbnail->value => ['enabled' => true], ImagePreset::Small->value => ['enabled' => true], ImagePreset::Medium->value => ['enabled' => true]],
    /*
    |--------------------------------------------------------------------------
    | Output Conversion
    |--------------------------------------------------------------------------
    | Auto-convert uploaded images to a bounded modern format (WebP/AVIF).
    | Creates an aspect-ratio-preserving variation, retrievable
    | via $media->getUrl('optimized').
    */
    'output_conversion' => ['enabled' => env('NVL_MEDIA_OPTIMIZED_IMAGE_ENABLED', true)],
    /*
    |--------------------------------------------------------------------------
    | Allowed Storage Disks (Security)
    |--------------------------------------------------------------------------
    | Only these disks can be selected via HTTP or direct upload actions.
    */
    'allowed_disks' => ['local', 'public'],
    'locks' => ['mutation' => ['store' => env('NVL_MEDIA_MUTATION_LOCK_STORE')], 'deduplication' => ['store' => env('NVL_MEDIA_DEDUPLICATION_LOCK_STORE')], 'multipart' => ['store' => env('NVL_MEDIA_MULTIPART_LOCK_STORE')]],
    'connections' => ['owner_slot_operations' => null],
];
