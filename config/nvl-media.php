<?php

declare(strict_types=1);

use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\ImageCompression;
use Nvl\Media\Enums\ImageFit;
use Nvl\Media\Enums\ImageFormat;
use Nvl\Media\Enums\ImagePreset;
use Nvl\Media\Enums\MediaImageDriver;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\NullMediaContentScanner;
use Nvl\Media\Services\PortableMediaSearchDriver;
use Nvl\Media\Services\S3MultipartUploadGateway;

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'models' => ['media' => Media::class],
    'catalog' => [
        // Only reviewed user-facing scalars cross from the platform catalog.
        'metadata_keys' => ['alt', 'attribution', 'caption', 'copyright', 'description', 'title'],
        'max_tags' => 25,
        'max_tag_length' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    | The package keeps ownership checks as its standalone default. When the
    | authenticated model exposes Spatie Permission's role/permission methods,
    | these optional grants may authorize cross-owner operations. Global roles
    | are intentionally opt-in to avoid silently elevating a common role name.
    */
    'authorization' => [
        'spatie_permission' => [
            'enabled' => env('NVL_MEDIA_SPATIE_PERMISSION_ENABLED', false),
            'global_roles' => array_values(array_filter(array_map(
                static fn (string $role): string => trim($role),
                explode(',', (string) env('NVL_MEDIA_GLOBAL_ROLES', '')),
            ))),
            'global_permission' => 'nvl.media.manage',
            'ability_permissions' => [
                'list_all' => 'nvl.media.view-any',
                'view' => 'nvl.media.view-any',
                'download' => 'nvl.media.download-any',
                'associate' => 'nvl.media.associate-any',
                'mutate' => 'nvl.media.update-any',
                'delete' => 'nvl.media.delete-any',
                'reuse' => 'nvl.media.reuse-any',
                'manage_staging' => 'nvl.media.manage-staging',
            ],
        ],
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'owner_slots' => [
        'copy' => [
            // Only explicitly approved, user-facing metadata is inherited by a
            // copied Media record. Consumers may replace this list when their
            // domain defines additional safe scalar metadata keys.
            'metadata_keys' => [
                'alt',
                'alt_text',
                'attribution',
                'author',
                'caption',
                'copyright',
                'credit',
                'description',
                'license',
                'license_url',
                'photographer',
                'source',
                'source_url',
                'title',
            ],
        ],
        'idempotency' => [
            'processing_timeout_minutes' => 30,
            'retention_days' => 7,
            'prune_chunk' => 500,
        ],
    ],

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

    // Optional visibility defaults. Explicit upload and slot disks take precedence.
    // These disks must also be defined in filesystems.php and allowed_disks.
    'public_disk' => null,
    'private_disk' => null,

    /*
    |--------------------------------------------------------------------------
    | S3-Compatible Object Storage
    |--------------------------------------------------------------------------
    | Modern S3 buckets should normally disable ACLs and keep objects private
    | at rest. Public Media records remain reusable and are delivered through
    | the public asset route/CDN policy. Enable ACL visibility only for buckets
    | intentionally configured to accept public-read/private object ACLs.
    */
    's3' => [
        'use_acl_visibility' => env('NVL_MEDIA_S3_USE_ACL_VISIBILITY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Root Folder
    |--------------------------------------------------------------------------
    | The fallback folder within the selected disk; an empty string omits it.
    | Models define their own subfolder structure via collection path templates.
    | During in-place adoption, set this to an empty string when persisted folder
    | values already contain the complete path below the disk root.
    */
    'root_folder' => env('NVL_MEDIA_ROOT_FOLDER', 'media'),

    // Disk-specific relative roots override root_folder, including empty strings.
    // Example: ['public' => 'media', 'private' => '', 's3' => 'assets/uploads'].
    'root_folders' => [],

    'adoption' => [
        'path_sample_size' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Path Template
    |--------------------------------------------------------------------------
    | Fallback path for collections that don't define their own template.
    | Supports {model_type}, {model_id}, {id}, {collection}, {slug},
    | {date}, {year}, {month}, {day}, and any model attribute.
    */
    'default_path' => 'misc',

    /*
    |--------------------------------------------------------------------------
    | Conversions Subdirectory
    |--------------------------------------------------------------------------
    */
    'conversions_folder' => 'conversions',

    /*
    |--------------------------------------------------------------------------
    | Upload Constraints
    |--------------------------------------------------------------------------
    */
    'max_file_size' => 10 * 1024 * 1024,
    'max_files_per_upload' => 10,

    'sources' => [
        'remote' => [
            // URL ingestion is an explicit capability. Ordinary request, local,
            // disk, and base64 uploads do not incur its DNS/cURL boundary.
            'enabled' => env('NVL_MEDIA_REMOTE_SOURCES_ENABLED', false),
            'allowed_ports' => [80, 443],
            'connect_timeout' => (int) env('NVL_MEDIA_REMOTE_CONNECT_TIMEOUT', 5),
            'total_timeout' => (int) env('NVL_MEDIA_REMOTE_TOTAL_TIMEOUT', 30),
            'redirects' => (int) env('NVL_MEDIA_REMOTE_REDIRECTS', 5),
            'maximum_bytes' => (int) env('NVL_MEDIA_REMOTE_MAXIMUM_BYTES', 10 * 1024 * 1024),
            'verify_connected_ip' => env('NVL_MEDIA_REMOTE_VERIFY_CONNECTED_IP', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content / Malware Scanner
    |--------------------------------------------------------------------------
    | Configure an implementation of MediaContentScanner when accepting
    | untrusted documents or archives. The default performs no external scan.
    */
    'content_scanner' => NullMediaContentScanner::class,
    'scanner' => [
        'required' => env('NVL_MEDIA_SCANNER_REQUIRED', false),
        'allow_noop' => env('NVL_MEDIA_ALLOW_NOOP_SCANNER', false),
        'untrusted_uploads' => env('NVL_MEDIA_UNTRUSTED_UPLOADS', true),
    ],

    'multipart' => [
        // Direct uploads are opt-in. Every completed object stays pending_scan
        // until MediaScan::finalize() accepts a scanner attestation. Production
        // diagnostics require a recoverable gateway, a real scanner, and a
        // central lock store.
        'enabled' => env('NVL_MEDIA_MULTIPART_ENABLED', false),
        'gateway' => S3MultipartUploadGateway::class,
        'session_minutes' => 60,
        'minimum_part_size' => 5 * 1024 * 1024,
        'maximum_part_size' => 5 * 1024 * 1024 * 1024,
        'maximum_parts' => 10_000,
        'maximum_size' => 5 * 1024 * 1024 * 1024,
        'lock' => [
            'seconds' => (int) env('NVL_MEDIA_MULTIPART_LOCK_SECONDS', 300),
            'wait_seconds' => (int) env('NVL_MEDIA_MULTIPART_LOCK_WAIT_SECONDS', 30),
        ],
    ],

    'query' => [
        'maximum_page_size' => 100,
        'search_driver' => PortableMediaSearchDriver::class,
    ],

    'reconciliation' => [
        'orphan_age_minutes' => 1440,
        'restrict_to_root' => true,
        'page_size' => 500,
    ],

    'deployment' => [
        'multi_node' => env('NVL_MEDIA_MULTI_NODE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    | Production should use Redis, SQS, or another durable queue. A null
    | connection inherits Core's queue connection, then queue.default. Worker timeout
    | must remain below retry_after (or SQS visibility timeout).
    */
    'queue' => [
        'enabled' => env('NVL_MEDIA_QUEUE_ENABLED', true),
        'connection' => env('NVL_MEDIA_QUEUE_CONNECTION'),
        'name' => env('NVL_MEDIA_QUEUE'),
        'jobs' => [
            'generate' => [
                'tries' => 3,
                'timeout' => 60,
                'backoff' => [10, 30, 90],
                'unique_for' => 1800,
            ],
            'dispatch' => [
                'tries' => 3,
                'timeout' => 60,
                'backoff' => [10, 30, 90],
                'unique_for' => 1800,
            ],
            'regenerate' => [
                'tries' => 1,
                'timeout' => 60,
                'backoff' => [60],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing Driver
    |--------------------------------------------------------------------------
    */
    'image_driver' => MediaImageDriver::resolve(env('NVL_MEDIA_IMAGE_DRIVER', 'gd')),

    /*
    |--------------------------------------------------------------------------
    | Centralized Asset Delivery
    |--------------------------------------------------------------------------
    | Route-backed media URLs exposed by the Media module.
    | These settings control URL builders, signed private links,
    | cache headers, and named variation query parameters.
    */
    'assets' => [
        'public_route_name' => 'nvl.media.assets.show',
        'authenticated_route_name' => 'nvl.media.authenticated.show',
        'private_route_name' => 'nvl.media.private.show',
        'public_route_middleware' => ['throttle:120,1'],
        'private_route_middleware' => [],
        'private_owner_fallback' => 'system',
        'public_cache_control' => 'public, max-age=31536000, immutable',
        'private_cache_control' => 'private, max-age=0, no-store',
        'allowed_parameters' => ['v'],
    ],

    /* Delivery choices are independent of filesystem drivers and object ACLs. */
    'delivery' => [
        'defaults' => [
            'public' => ['mode' => 'application', 'allowed' => ['application', 'direct']],
            'private' => ['mode' => 'application', 'allowed' => ['application', 'signed_application', 'temporary_storage']],
        ],
        'maximum_ttl' => 300,
        // A disk may override either visibility policy, or set it to false.
        'disks' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Naming
    |--------------------------------------------------------------------------
    */
    'hash_filenames' => true,
    'transliterate' => false,
    'allow_duplicates' => false,

    'deduplication' => [
        'allow_anonymous_private' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared Upload Deduplication Lock
    |--------------------------------------------------------------------------
    | Deduplicated shared uploads are serialized before file storage so two
    | concurrent requests cannot both create the same digest row. The default
    | cache store is the application database store unless overridden here.
    */
    'deduplication_lock' => [
        'enabled' => env('NVL_MEDIA_DEDUPLICATION_LOCK_ENABLED', true),
        'seconds' => (int) env('NVL_MEDIA_DEDUPLICATION_LOCK_SECONDS', 30),
        'wait_seconds' => (int) env('NVL_MEDIA_DEDUPLICATION_LOCK_WAIT_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared Media Mutation Lock
    |--------------------------------------------------------------------------
    | Replacement, movement, deletion, and variation mutations share this
    | lock. Multi-node production installations must select a central store.
    */
    'mutation_lock' => [
        'enabled' => env('NVL_MEDIA_MUTATION_LOCK_ENABLED', true),
        'seconds' => (int) env('NVL_MEDIA_MUTATION_LOCK_SECONDS', 300),
        'wait_seconds' => (int) env('NVL_MEDIA_MUTATION_LOCK_WAIT_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported File Types (extension => MIME type or list of MIME aliases)
    |--------------------------------------------------------------------------
    */
    'file_types' => [
        // Images
        'svg' => ['image/svg+xml', 'application/xml', 'text/xml'],
        'bmp' => 'image/bmp',
        'gif' => 'image/gif',
        'png' => 'image/png',
        'ico' => ['image/vnd.microsoft.icon', 'image/x-icon'],
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',

        // Documents
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf' => 'application/pdf',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',

        // Code / Data
        'json' => ['application/json', 'text/json'],
        'xml' => ['application/xml', 'text/xml'],

        // Video
        'mp4' => 'video/mp4',
        'mpeg' => 'video/mpeg',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',

        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => ['audio/wav', 'audio/x-wav'],
        'ogg' => 'audio/ogg',
        'aac' => ['audio/aac', 'audio/x-hx-aac-adts'],
        'flac' => 'audio/flac',

        // Archives
        'zip' => 'application/zip',
        'rar' => ['application/vnd.rar', 'application/x-rar'],
        '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar',
        'gz' => ['application/gzip', 'application/x-gzip'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Group Types (extension categorization)
    |--------------------------------------------------------------------------
    */
    'group_types' => [
        'image' => ['svg', 'bmp', 'gif', 'png', 'ico', 'jpeg', 'jpg', 'webp', 'avif'],
        'document' => ['doc', 'docx', 'pdf', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt'],
        'video' => ['mp4', 'mpeg', 'webm', 'mov'],
        'audio' => ['mp3', 'wav', 'ogg', 'aac', 'flac'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
        'code' => ['json', 'xml'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Constraints
    |--------------------------------------------------------------------------
    */
    'image_constraints' => [
        'max_width' => 4096,
        'max_height' => 4096,
        // Largest width x height the package decodes for variations and slot
        // conversions; a source declaring more is refused before decoding.
        'max_pixels' => 64_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Optimization
    |--------------------------------------------------------------------------
    | Controls original-image optimization behaviour applied by ImageOptimizationService.
    | skip_formats: file extensions that bypass optimization entirely (e.g. SVG, GIF).
    */
    'optimization' => [
        'skip_formats' => ['svg', 'gif'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Format Profiles
    |--------------------------------------------------------------------------
    | Presets inherit compression and quality from their output format unless
    | they override those values. Lossless mode maps to quality 100 in the
    | selected Spatie Image driver.
    */
    'image_formats' => [
        ImageFormat::Webp->value => [
            'compression' => ImageCompression::Lossy,
            'quality' => (int) env('NVL_MEDIA_WEBP_QUALITY', 82),
        ],
        ImageFormat::Avif->value => [
            'compression' => ImageCompression::Lossy,
            'quality' => (int) env('NVL_MEDIA_AVIF_QUALITY', 60),
        ],
        ImageFormat::Jpeg->value => [
            'compression' => ImageCompression::Lossy,
            'quality' => (int) env('NVL_MEDIA_JPEG_QUALITY', 85),
        ],
        ImageFormat::Png->value => [
            'compression' => ImageCompression::Lossless,
            'quality' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Generate Image Variations on Upload
    |--------------------------------------------------------------------------
    | When true, enabled variation presets are automatically generated
    | after a successful upload for media types that support variations.
    | Lifecycle: upload → preset variations → output conversion (if enabled).
    | Variations are stored as MediaImageVariation records and served via
    | the ?v=label query parameter on asset URLs.
    */
    'auto_generate_variations' => true,

    /*
    |--------------------------------------------------------------------------
    | Image Variation Presets
    |--------------------------------------------------------------------------
    | These three defaults cover exact square previews and proportional cards.
    | Replace this array in the published config to change or add presets.
    | ImageFit::Max preserves aspect ratio and never upscales smaller images.
    */
    'image_variation_presets' => [
        ImagePreset::Thumbnail->value => [
            'width' => 160,
            'height' => 160,
            'fit' => ImageFit::Crop,
            'format' => ImageFormat::Webp,
            'enabled' => true,
        ],
        ImagePreset::Small->value => [
            'max_size' => 480,
            'fit' => ImageFit::Max,
            'format' => ImageFormat::Webp,
            'enabled' => true,
        ],
        ImagePreset::Medium->value => [
            'max_size' => 960,
            'fit' => ImageFit::Max,
            'format' => ImageFormat::Webp,
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Conversion
    |--------------------------------------------------------------------------
    | Auto-convert uploaded images to a bounded modern format (WebP/AVIF).
    | Creates an aspect-ratio-preserving variation, retrievable
    | via $media->getUrl('optimized').
    */
    'output_conversion' => [
        'enabled' => env('NVL_MEDIA_OPTIMIZED_IMAGE_ENABLED', true),
        'format' => ImageFormat::Webp,
        'compression' => ImageCompression::Lossy,
        'max_size' => (int) env('NVL_MEDIA_IMAGE_MAX_SIZE', 1200),
        'fit' => ImageFit::Max,
        'skip_formats' => ['svg', 'gif'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Variation Object Naming
    |--------------------------------------------------------------------------
    | The compatible default is <source-hash>-<label>.<extension>. Consumers
    | may opt into dimension-bearing keys with:
    | {basename}--{label}-{width}x{height}.{extension}
    */
    'variation_naming' => [
        'pattern' => '{basename}-{label}.{extension}',
    ],

    /*
    |--------------------------------------------------------------------------
    | SVG Security Scanning
    |--------------------------------------------------------------------------
    | When enabled, SVG files are scanned for embedded scripts, event
    | handlers, XXE declarations, and other injection vectors before
    | being stored. Malicious SVGs are rejected with an exception.
    */
    'svg_scanning' => true,

    /*
    |--------------------------------------------------------------------------
    | Cleanup Behavior
    |--------------------------------------------------------------------------
    */
    'delete_media_on_model_delete' => true,
    'delete_files_on_media_delete' => true,

    /*
    |--------------------------------------------------------------------------
    | Reusable Public Asset Protection
    |--------------------------------------------------------------------------
    |
    | Prevent ordinary global deletion while a public asset is attached to
    | multiple owners. MediaLibrary accepts force: true for intentional
    | administrative removal.
    |
    */
    'prevent_deleting_reused_public_media' => true,
    'clean_empty_directories' => true,

    /*
    |--------------------------------------------------------------------------
    | Allowed Storage Disks (Security)
    |--------------------------------------------------------------------------
    | Only these disks can be selected via HTTP or direct upload actions.
    */
    'allowed_disks' => ['local', 'public'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Associable Types (Security)
    |--------------------------------------------------------------------------
    | Only these nvl-core owner aliases or compatibility model classes can be
    | used in API attach/detach operations.
    | An empty list intentionally denies every associable type.
    */
    'allowed_associable_types' => [],

    /*
    |--------------------------------------------------------------------------
    | Associable Mutation Abilities
    |--------------------------------------------------------------------------
    | Ability map used by the Media API when attaching, detaching, or reordering
    | media on a target model. Shared alias keys precede compatibility class
    | keys; types not listed here default to `update`.
    */
    'associable_mutation_abilities' => [],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */
    'cache_file_existence' => true,
    'cache_ttl' => 60,
    'locks' => ['mutation' => ['store' => env('NVL_MEDIA_MUTATION_LOCK_STORE')], 'deduplication' => ['store' => env('NVL_MEDIA_DEDUPLICATION_LOCK_STORE')], 'multipart' => ['store' => env('NVL_MEDIA_MULTIPART_LOCK_STORE')]],
    'connections' => ['owner_slot_operations' => null],
    'tables' => ['owner_slot_operations' => MediaTables::OwnerSlotOperations],
];
