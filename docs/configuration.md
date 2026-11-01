# Media configuration

Package routes and the deprecated Tenancy bridge have been removed. Consumers configure their own HTTP adapters. Native ownership uses declared resources and explicit contexts.

Publish the package configuration with:

```bash
php artisan vendor:publish --tag=nvl-media-config
```

Keep environment reads in `config/nvl-media.php`, then use `php artisan config:cache` for production. Run `php artisan nvl:media:doctor --production --strict` against the cached configuration.

The values below describe the major 7 runtime defaults in `config/nvl-media.php`. The provider publishes these full defaults to the host config file. `resources/config/nvl-media.php` is a separate common-settings template for configuration tooling. Application configuration is merged with runtime defaults. Review list/map overrides after upgrades, especially permission maps, metadata allowlists and delivery modes. Review [UPGRADING.md](../UPGRADING.md) before rollout. The `nvl-media-skills` publish tag installs agent guidance into `.agents/skills`; `nvl-media-translations` publishes language resources.

## Configured models

`nvl-media.models.media` defaults to `Nvl\Media\Models\Media::class`.
Set it to a concrete subclass before application boot. Media validates required
casts, UUID keys, its custom builder and canonical persistence hooks. The shared
Core model registry preserves existing native morph identities. Owned consumers
override `ownershipDefinition()` with a native column or concrete primary pivot;
ordinary applications retain the unscoped default.

## Optional native ownership

Unowned installations use the unscoped default and do not require `nvl/ownership`. A model-declared native column/pivot or adopted ownership storage requires a compatible `nvl/ownership:^7.0` runtime and explicit resource/context admission. Declared ownership fails closed if its runtime is absent. Media translations and variations inherit their canonical Media parent's ownership.

New owned storage uses the admitted `owners/<scope-hash>` or `central` partition below the configured root. The retired Tenancy bridge's configuration and tenant worklist APIs are not supported. Existing tenant-named physical columns or paths are historical facts to preserve during controlled adoption, not automatic rename instructions.

## Consumer HTTP routes

The package registers no routes or management controllers. Register the named asset routes in [Asset delivery](#asset-delivery), then apply application authentication, authorization, throttling and response headers. Middleware lists in `nvl-media.assets` are configuration values for host adapters; the provider does not apply them. Doctor validates package configuration and infrastructure, not the security of consumer HTTP adapters. Verify those adapters in the host's tests.

See [HTTP integration](http-api.md).

## Authorization

`nvl-media.authorization.spatie_permission` is an optional duck-typed bridge. The package does not require `spatie/laravel-permission`.

| Key | Default | Meaning |
| --- | --- | --- |
| `enabled` | `false` | Explicitly opt into compatible role/permission methods when present |
| `global_roles` | `[]` | Roles granting every media ability |
| `global_permission` | `nvl.media.manage` | Permission granting every media ability |
| `ability_permissions.list_all` | `nvl.media.view-any` | Cross-owner library listing |
| `ability_permissions.view` | `nvl.media.view-any` | Cross-owner view |
| `ability_permissions.download` | `nvl.media.download-any` | Cross-owner download |
| `ability_permissions.associate` | `nvl.media.associate-any` | Cross-owner attach/detach |
| `ability_permissions.mutate` | `nvl.media.update-any` | Cross-owner update/replace/move/variation work |
| `ability_permissions.delete` | `nvl.media.delete-any` | Cross-owner delete |
| `ability_permissions.reuse` | `nvl.media.reuse-any` | Cross-owner public reuse |
| `ability_permissions.manage_staging` | `nvl.media.manage-staging` | Adopt another actor's staged media through owner-slot workflows |

Environment:

```dotenv
NVL_MEDIA_SPATIE_PERMISSION_ENABLED=true
NVL_MEDIA_GLOBAL_ROLES=admin,super-admin
```

Role names are empty by default so an installation cannot silently elevate an existing role. Missing methods, roles, permissions, or guard compatibility fail closed. Replace `MediaAuthorization` when the application needs a different policy model.

## Migrations

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.migrations.enabled` | `true` | Load package migrations automatically |

Choose one migration owner. Keep this `true` for automatic package migrations, or publish `nvl-media-migrations` and set it to `false` before running the host-owned copies. Review populated schemas and duplicate migration names before `php artisan migrate`; do not run both sources.

## Catalog copies

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.catalog.metadata_keys` | reviewed presentation/provenance key list | Scalar metadata allowed to cross from a granted central asset into an owner copy |
| `nvl-media.catalog.max_tags` | `25` | Maximum projected catalog tags |
| `nvl-media.catalog.max_tag_length` | `100` | Maximum characters in each projected tag |

Catalog metadata and tags are projected through these bounds rather than copied verbatim. Use `MediaOwnerCatalog` with native owner admission and explicit `MediaCatalogAccess` grants. Imports create a private, independently verified copy on the source disk, so that disk must admit Private visibility; no destination disk is selected implicitly. A public-only CDN source cannot hold this copy. Staging and persistence recheck the policy; discard remains available after the policy is disabled.

## Owner-slot operation identity

The owner-slot mutation ledger is package workflow infrastructure. Consumers do
not query or write this table directly.

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.owner_slots.copy.metadata_keys` | safe presentation/provenance key list | Scalar Media metadata keys inherited by canonical copies; consumer lists replace the default list |
| `nvl-media.connections.owner_slot_operations` | `null` | Optional operation-ledger connection; inherit Media/Core/Laravel |
| `nvl-media.tables.owner_slot_operations` | `nvl_media_owner_slot_operations` | Validated operation-ledger table name |
| `nvl-media.owner_slots.idempotency.processing_timeout_minutes` | `30` | Processing lease before an exact request may recover; range 1-1,440 |
| `nvl-media.owner_slots.idempotency.retention_days` | `7` | Age after which completed and failed claims may be pruned |
| `nvl-media.owner_slots.idempotency.prune_chunk` | `500` | Maximum terminal rows deleted per bounded pruning query; hard maximum 1,000 |

The ledger stores actor/owner/slot identity, a SHA-256 request digest, lifecycle
state, stable failure code, and nullable result Media UUID. It never stores the
request payload or exception messages. The same UUID and canonical request may
replay a completed result; using that UUID for another actor, owner, slot,
operation, or payload fails closed. A failed exact request may be claimed again.
Processing claims use a renewable lease. Recovery rotates the operation UUID so
the stale claim can no longer complete. Long-running orchestrators renew before
the configured timeout.

A separate ledger connection is a durable saga boundary, not a cross-database
transaction. The owner mutation must be safe to reconcile or retry after a
crash between the durable mutation and ledger completion. Prefer the Media
database connection when operational isolation is unnecessary. Doctor validates
the configured table, columns, indexes, lifecycle bounds, atomic mutation store,
and the bounded owner type/slot pairs observed in retained operations.

Copy metadata is deny-by-default outside the configured list. Values must be
scalar or null. Do not include secrets, credentials, provider responses,
redaction/workflow state, storage paths/hashes, or association metadata.

## Storage

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.disk` | `NVL_MEDIA_FILESYSTEM_DISK`, then `FILESYSTEM_DISK`, then `local` | Default media disk |
| `nvl-media.s3.use_acl_visibility` | `false` | Apply per-object ACL visibility on intentionally ACL-enabled S3 buckets |
| `nvl-media.public_disk` | `null` | Optional public-upload default; explicit upload/slot disks take precedence |
| `nvl-media.private_disk` | `null` | Optional private-upload default; explicit upload/slot disks take precedence |
| `nvl-media.root_folder` | `media` | Fallback disk-relative folder prefix; an empty string omits the prefix |
| `nvl-media.root_folders` | `[]` | Disk-name-to-folder overrides; an explicit empty string means the disk root |
| `nvl-media.adoption.path_sample_size` | `25` | Maximum live Media rows Doctor samples against physical storage; soft-deleted tombstones are excluded |
| `nvl-media.default_path` | `misc` | Fallback logical folder template; an empty string omits the subfolder |
| `nvl-media.conversions_folder` | `conversions` | Variation subdirectory |
| `nvl-media.allowed_disks` | `['local', 'public']` | Security allowlist for selected upload and relocation disks |

Environment:

```dotenv
NVL_MEDIA_FILESYSTEM_DISK=s3
NVL_MEDIA_S3_USE_ACL_VISIBILITY=false
NVL_MEDIA_ROOT_FOLDER=media
```

Production S3-compatible disks should use `throw=true` and private objects at rest. Public media is delivered publicly by package policy; it does not require a `public-read` object ACL.

Laravel owns each disk's physical root, bucket and credentials in `config/filesystems.php`. Media folders are relative to that disk; Media never adds its physical root twice. Configure any number of disks independently:

```php
// config/nvl-media.php
'disk' => 'private',
'public_disk' => 'public',
'private_disk' => 'private',
'allowed_disks' => ['public', 'private', 's3'],
'root_folder' => 'media',
'root_folders' => [
    'public' => 'media',
    'private' => '',
    's3' => 'assets/uploads',
],
```

Define `private` and `s3` in `filesystems.php` before selecting them. Disk names do not determine visibility: the host must keep its private disk inaccessible through public web/CDN paths, while S3 can keep even public Media objects private at rest for route-backed delivery.

Native `MediaUploader::upload()` and `MediaMultipart::initiate()` require an explicit disk. For slot-based uploads, pass `MediaSlot::resolveDisk($isPublic)`: it selects an explicit `useDisk()` value, then the configured visibility default, then `nvl-media.disk` / `filesystems.default` / `local`. Consumer-owned wrappers may offer an explicit override. Null visibility defaults preserve single-disk behavior. These defaults do not extend `allowed_disks` or move existing records. Metadata writes reject visibility changes; use verified cross-disk `MediaLibrary::relocate()` instead.

An absent disk entry inherits `root_folder`; `''` overrides it and omits the base folder. Null, non-string roots, dot segments, traversal, null bytes, drive letters and URL roots are rejected. Nested relative folders are supported on local and object-storage disks. Use `''`, not `/`, for a disk root. Slot `path()` templates and `MediaUploader::upload(folderOverride: ...)` remain subfolders below that prefix:

```php
use Nvl\Media\MediaRegistration;
use Nvl\Media\Services\MediaUploader;

// $owner implements HasNvlMedia and registers a private "documents" slot.
$slot = MediaRegistration::forOwner($owner)->getSlot('documents')
    ?? throw new LogicException('Register the documents slot first.');

$invoice = app(MediaUploader::class)->upload(
    actor: $actor,
    file: $file,
    disk: 's3',
    model: $owner,
    slot: $slot,
    fileName: 'invoice.pdf',
    isPublic: false,
    folderOverride: 'invoices',
    allowDuplicates: true,
    deduplicateExisting: false,
    skipAutoVariations: true,
);
// On s3: assets/uploads/invoices/<opaque-name>.pdf
// folderOverride: '' uses <opaque-name>.pdf when that disk's root is also ''.
```

Both the base prefix and the logical folder must be empty for originals directly at the disk root. Use an empty slot `path('')` or set `default_path` to `''` to make that the host default. Variations still use the configured conversions subfolder. Declared native ownership retains its admitted `owners/<scope-hash>` or `central` partition even when the base prefix is empty. The example creates a Media asset; association is a separate authorized `MediaAttachments` operation.

Reconciliation uses the selected disk's prefix and admitted owner partition. Whole-disk orphan deletion is always refused. With an empty standalone prefix, default `restrict_to_root=true` also refuses orphan inventory; explicit `restrict_to_root=false` permits read-only whole-disk inventory. Record-based verification, replacement and deletion still work at the disk root.

Folder configuration describes existing physical layout; changing it does not move files. Keep the source disk's existing prefix until a controlled move completes. Configure a destination disk name and its desired prefix, preview `nvl:media:migrate-disk --from=<source> --to=<destination> --dry-run`, then run the move. Originals and variations use the destination prefix with checksum verification and rollback; identical prefixes retain existing paths. `--records-only` expects objects already copied to the displayed destination layout. Rebuild configuration caches and restart workers after changes; drain active multipart sessions before changing their disk prefix. Doctor validates `storage.configuration` and samples live persisted paths using `adoption.path_sample_size`; deleted tombstones are excluded.

See [S3 and object storage](s3.md).

## Ordinary upload limits

| Key | Default | Unit |
| --- | --- | --- |
| `nvl-media.max_file_size` | `10 * 1024 * 1024` | Bytes per file |
| `nvl-media.max_files_per_upload` | `10` | Suggested host request batch bound; native uploads accept one file |

Slot-specific `maxFileSize()` may further restrict an upload. Source boundaries and the authoritative ingestion service enforce applicable file limits. Consumers validate HTTP batch counts; no package request/controller applies `max_files_per_upload`.

## Remote sources

Remote URL ingestion is opt-in and has no effect on request, local, disk, string, or base64 uploads.

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.sources.remote.enabled` | `false` | Enable HTTP/HTTPS URL sources |
| `nvl-media.sources.remote.allowed_ports` | `[80, 443]` | Destination ports accepted on every hop |
| `nvl-media.sources.remote.connect_timeout` | `5` | cURL connect timeout in seconds |
| `nvl-media.sources.remote.total_timeout` | `30` | Complete request timeout in seconds |
| `nvl-media.sources.remote.redirects` | `5` | Maximum manually validated redirects |
| `nvl-media.sources.remote.maximum_bytes` | `10 * 1024 * 1024` | Streamed download byte ceiling |
| `nvl-media.sources.remote.verify_connected_ip` | `true` | Require the connected peer to match a validated pinned IP |

Environment:

```dotenv
NVL_MEDIA_REMOTE_SOURCES_ENABLED=false
NVL_MEDIA_REMOTE_CONNECT_TIMEOUT=5
NVL_MEDIA_REMOTE_TOTAL_TIMEOUT=30
NVL_MEDIA_REMOTE_REDIRECTS=5
NVL_MEDIA_REMOTE_MAXIMUM_BYTES=10485760
NVL_MEDIA_REMOTE_VERIFY_CONNECTED_IP=true
```

When enabled, every URL and redirect is restricted to HTTP/HTTPS, credential-free URLs, allowed ports, and public A/AAAA results. Requests are pinned to the validated result set while preserving hostname, TLS SNI, and certificate verification. Automatic redirects are disabled. Retries remain inside the validated IP set.

The connected-IP check may be disabled only for deterministic test transports or a controlled custom boundary. Production doctor rejects it when remote ingestion is enabled.

## Integrity

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.integrity.verification` | `required` | Stored size and SHA-256 verification policy |
| `nvl-media.integrity.provider_checksum` | `prefer` | Prefer trustworthy provider checksum metadata |
| `nvl-media.integrity.streamed_readback_fallback` | `true` | Stream-read the object when provider attestation is unavailable |

The production invariant is that every live row references a verified object. Do not disable streamed fallback unless the selected gateway provides equivalent trustworthy SHA-256 and size attestation and the application has validated that behavior.

## Scanner

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.content_scanner` | `NullMediaContentScanner::class` | Container implementation of `MediaContentScanner` |
| `nvl-media.scanner.required` | `false` | Require scanner policy |
| `nvl-media.scanner.allow_noop` | `false` | Permit the no-op scanner outside strict production checks |
| `nvl-media.scanner.untrusted_uploads` | `true` | Mark incoming content as untrusted for diagnostics |
| `nvl-media.svg_scanning` | `true` | Run built-in structural SVG scanning |

Environment:

```dotenv
NVL_MEDIA_SCANNER_REQUIRED=true
NVL_MEDIA_ALLOW_NOOP_SCANNER=false
NVL_MEDIA_UNTRUSTED_UPLOADS=true
```

The built-in SVG scanner and external malware scanner solve different problems. Keep SVG scanning enabled even when a malware scanner is bound. Production doctor fails when untrusted uploads use the no-op scanner.

## Multipart

Direct multipart uploads are disabled by default.

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.multipart.enabled` | `false` | Enable persisted provider sessions |
| `nvl-media.multipart.gateway` | `S3MultipartUploadGateway::class` | Provider implementation |
| `nvl-media.multipart.required_scan` | `true` | Require post-upload scanner attestation |
| `nvl-media.multipart.session_minutes` | `60` | Session lifetime |
| `nvl-media.multipart.minimum_part_size` | `5 MiB` | Non-final minimum part size |
| `nvl-media.multipart.maximum_part_size` | `5 GiB` | Maximum part size |
| `nvl-media.multipart.maximum_parts` | `10,000` | Maximum part count |
| `nvl-media.multipart.maximum_size` | `5 GiB` | Maximum complete object size |
| `nvl-media.locks.multipart.store` | application default | Cache store for session locks |
| `nvl-media.multipart.lock.seconds` | `300` | Lock lease |
| `nvl-media.multipart.lock.wait_seconds` | `30` | Maximum lock wait |

Environment:

```dotenv
NVL_MEDIA_MULTIPART_ENABLED=false
NVL_MEDIA_MULTIPART_LOCK_STORE=redis
NVL_MEDIA_MULTIPART_LOCK_SECONDS=300
NVL_MEDIA_MULTIPART_LOCK_WAIT_SECONDS=30
```

Enabling multipart in production requires a recoverable gateway, central atomic lock store, persisted session migration, real scanner attestation, expired-session pruning, provider integration tests, and a clean strict production doctor. Every completed object remains `pending_scan` until an exact native scan-result array passed to `MediaScan::finalize()` makes it available.

## Query

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.query.maximum_page_size` | `100` | Hard cap for authorized reader pagination |
| `nvl-media.query.search_driver` | `PortableMediaSearchDriver::class` | Implementation of `MediaSearchDriver` |

The portable driver performs moderate-dataset substring and JSON search. Bind or configure a PostgreSQL full-text or external implementation for larger search workloads without replacing `MediaReader`.

## Reconciliation

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.reconciliation.orphan_age_minutes` | `1440` | Minimum orphan age |
| `nvl-media.reconciliation.restrict_to_root` | `true` | Require a selected disk/owner prefix for orphan inventory; whole-disk orphan deletion is always refused |
| `nvl-media.reconciliation.page_size` | `500` | Object inventory page size |

Keep root restriction enabled. Cleanup remains opt-in at the command boundary and requires `--force` in production. Objects whose adapter cannot report reliable age remain report-only.

## Deployment topology

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.deployment.multi_node` | `false` | Tell doctor to require shared production infrastructure |

Environment:

```dotenv
NVL_MEDIA_MULTI_NODE=true
```

This flag does not create a cluster. It strengthens diagnostics for central locks, durable queues, and storage assumptions.

## Queues

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.queue.enabled` | `true` | Permit background variation workflows |
| `nvl-media.queue.connection` | `NVL_MEDIA_QUEUE_CONNECTION`, otherwise `null` | Inherit Core queue connection, then effective Laravel `queue.default`; explicit `sync` stays synchronous |
| `nvl-media.queue.name` | `NVL_MEDIA_QUEUE`, otherwise `null` | Inherit Core queue name, then the selected Laravel connection's queue |
| `nvl-media.queue.jobs.generate.tries` | `3` | Variation job attempts |
| `nvl-media.queue.jobs.generate.timeout` | `60` | Variation timeout in seconds |
| `nvl-media.queue.jobs.generate.backoff` | `[10, 30, 90]` | Retry backoff seconds |
| `nvl-media.queue.jobs.generate.unique_for` | `1800` | Queue uniqueness window |
| `nvl-media.queue.jobs.dispatch.*` | Same as generate | Dispatch job policy |
| `nvl-media.queue.jobs.regenerate.tries` | `1` | Regeneration batch attempts |
| `nvl-media.queue.jobs.regenerate.timeout` | `60` | Regeneration timeout |
| `nvl-media.queue.jobs.regenerate.backoff` | `[60]` | Regeneration retry backoff |

Environment:

```dotenv
NVL_MEDIA_QUEUE_ENABLED=true
NVL_MEDIA_QUEUE_CONNECTION=redis
NVL_MEDIA_QUEUE=media
```

Production background work requires a durable queue. Queue `retry_after` or provider visibility timeout must be greater than every corresponding media job timeout. Queue uniqueness is an optimization; media mutation locks and revision checks remain the correctness boundary.

See [Image variations and queues](images-and-queues.md).

## Image driver and constraints

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.image_driver` | resolved from `NVL_MEDIA_IMAGE_DRIVER`, default `gd` | `gd`, `imagick`, or `vips` |
| `nvl-media.image_constraints.max_width` | `4096` | Suggested host upload dimension maximum |
| `nvl-media.image_constraints.max_height` | `4096` | Suggested host upload dimension maximum |
| `nvl-media.optimization.skip_formats` | `['svg', 'gif']` | Original formats not optimized |

Environment:

```dotenv
NVL_MEDIA_IMAGE_DRIVER=gd
```

The runtime must provide the selected driver and every encoder required by enabled output formats. Doctor checks the configured driver and encoders. Consumers must apply input dimension limits in their own request/slot validation; `image_constraints` is not an automatic pixel-limit check in the native uploader.

## Image format profiles

`nvl-media.image_formats` defines default compression and quality by output format:

| Format | Compression | Quality |
| --- | --- | --- |
| WebP | lossy | `NVL_MEDIA_WEBP_QUALITY`, default `82` |
| AVIF | lossy | `NVL_MEDIA_AVIF_QUALITY`, default `60` |
| JPEG | lossy | `NVL_MEDIA_JPEG_QUALITY`, default `85` |
| PNG | lossless | `100` |

These values are application defaults, not universal visual targets. Validate them against representative images.

## Named variations

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.auto_generate_variations` | `true` | Dispatch enabled named definitions after committed uploads |
| `nvl-media.image_variation_presets` | `thumb`, `small`, `medium` | Global named definitions |
| `nvl-media.output_conversion.enabled` | `true` | Generate the `optimized` output |
| `nvl-media.output_conversion.format` | WebP | Optimized format |
| `nvl-media.output_conversion.compression` | lossy | Optimized compression |
| `nvl-media.output_conversion.max_size` | `NVL_MEDIA_IMAGE_MAX_SIZE`, default `1200` | Longest-edge bound |
| `nvl-media.output_conversion.fit` | max | Preserve aspect ratio and never upscale |
| `nvl-media.output_conversion.skip_formats` | `['svg', 'gif']` | Formats excluded from optimized output |
| `nvl-media.variation_naming.pattern` | `{basename}-{label}.{extension}` | Stored variation key pattern |

Environment:

```dotenv
NVL_MEDIA_OPTIMIZED_IMAGE_ENABLED=true
NVL_MEDIA_IMAGE_MAX_SIZE=1200
```

Supported naming tokens are `{basename}`, `{label}`, `{width}`, `{height}`, and `{extension}`. Labels and final paths are validated. Changing the naming pattern requires controlled regeneration.

Dynamic transformation query parameters are unsupported. Only persisted named labels may be delivered.

## Asset delivery

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.assets.public_route_name` | `nvl.media.assets.show` | Public route name |
| `nvl-media.assets.authenticated_route_name` | `nvl.media.authenticated.show` | Private route which authenticates and authorizes each requester |
| `nvl-media.assets.private_route_name` | `nvl.media.private.show` | Signed private route name |
| `nvl-media.assets.public_route_middleware` | `['throttle:120,1']` | Suggested public middleware for the consumer adapter |
| `nvl-media.assets.private_route_middleware` | `[]` | Suggested extra private middleware for the consumer adapter |
| `nvl-media.assets.private_owner_fallback` | `system` | Owner token for private media lacking uploader identity |
| `nvl-media.assets.public_cache_control` | `public, max-age=31536000, immutable` | Versioned public response cache header |
| `nvl-media.assets.private_cache_control` | `private, max-age=0, no-store` | Private response cache header |
| `nvl-media.assets.allowed_parameters` | `['v']` | Asset query allowlist |
| `nvl-media.delivery.defaults.public` | `application`; allows `application`, `direct` | Public policy inherited by disks without an override |
| `nvl-media.delivery.defaults.private` | `application`; allows `application`, `signed_application`, `temporary_storage` | Private policy inherited by disks without an override |
| `nvl-media.delivery.disks` | `[]` | Policies keyed by Laravel filesystem disk name |
| `nvl-media.delivery.maximum_ttl` | `300` | Maximum bearer-link lifetime in seconds; a disk may override it |

Physical roots, credentials, buckets, provider endpoints and CDN base URLs remain in `filesystems.php`. Delivery policy applies equally to local and remote disks, originals and variations:

```php
'delivery' => [
    'maximum_ttl' => 300,
    'disks' => [
        'website-cdn' => [
            'public' => ['mode' => 'direct', 'allowed' => ['direct', 'application']],
            'private' => false,
        ],
        'website-masked' => [
            'public' => ['mode' => 'application', 'allowed' => ['application']],
            'private' => false,
        ],
        'documents' => [
            'public' => false,
            'private' => ['mode' => 'application', 'allowed' => ['application', 'signed_application', 'temporary_storage']],
            'maximum_ttl' => 120,
        ],
    ],
],
```

An omitted disk or visibility policy inherits defaults. Set a visibility to `false` to reject its uploads and delivery on that disk. The `mode` selects the default; `allowed` bounds explicit `MediaDeliveryMode` overrides. Unknown settings, invalid modes, unsupported public/private combinations and invalid lifetime bounds fail explicitly. Policies do not prove provider bucket privacy: keep private data outside publicly exposed buckets or prefixes.

`application` returns a consumer-owned route. For private media it returns an authenticated URL without minting a bearer capability. Ordinary private URL reads reject bearer-mode defaults; issue the capability explicitly, or pass an allowed Application override. The route must check the actual requesting actor's Download permission every time. The application must stream/proxy bytes to keep the storage URL hidden; a redirect exposes its destination. No route failure silently switches to a storage URL.

Pass the serving mode to `MediaAssetService::resolve()`: Application for public/authenticated routes, SignedApplication for signed bearer routes. Resolution enforces current visibility and mode admission, including requests using already-issued URLs. Reconciliation verifies the effective mode and lifetime bound without fetching a remote URL.

Issue `signed_application` links through `MediaUrlResolver::signedUrl($actor, $media, $expiration, $parameters)`. Issue provider `temporary_storage` links through `temporaryUrl($actor, $media, $expiration, $variation)`. Both reload canonical facts on the primary database and check Download authority. Expiration must be in the future and within the disk's maximum (1–604800 seconds). Provider URLs never fall back to unsigned delivery. Owned private assets require their scoped application route and cannot use provider URLs.

Signed application links are replayable bearer capabilities until expiry. Consumer endpoints must validate the signature, canonical owner/scope, current availability and signed source revision. Changing a source invalidates its application links; native provider URLs may remain usable until expiry. An explicit named variation which is unavailable or cannot be represented by the route fails instead of granting its original.

Public application URLs and direct variation URLs carry a content version; originals use opaque immutable object names. Unversioned public route requests must revalidate. Visibility changes require `MediaLibrary::relocate()` to another admitted disk; metadata writes accept only unchanged visibility. Configuration changes never move existing bytes, and making a previously public asset private cannot recall cached copies already published through a CDN. Existing multipart sessions recheck policy before signing/completion; abort remains available for cleanup.

## Display filename and duplication

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.hash_filenames` | `true` | Retained setting; storage identities remain cryptographically random |
| `nvl-media.transliterate` | `false` | Transliterate non-ASCII display filenames during sanitization |
| `nvl-media.allow_duplicates` | `false` | Global default for caller-requested duplicate records |
| `nvl-media.deduplication.allow_anonymous_private` | `false` | Permit anonymous private digest reuse |

`nvl-media.hash_filenames` must not be treated as a switch back to display-derived object names. Hardened ingestion always generates an opaque object name with only the validated canonical extension. The uploader's `fileName` and `MediaLibrary::rename()` affect presentation metadata.

Private deduplication is scoped by uploader type and identifier. Anonymous private uploads create unique records by default. Public shared slots deduplicate by validated content digest, disk, and visibility.

## Atomic locks

### Digest lock

| Key | Default |
| --- | --- |
| `nvl-media.deduplication_lock.enabled` | `true` |
| `nvl-media.locks.deduplication.store` | application default cache store |
| `nvl-media.deduplication_lock.seconds` | `30` |
| `nvl-media.deduplication_lock.wait_seconds` | `30` |

Environment:

```dotenv
NVL_MEDIA_DEDUPLICATION_LOCK_ENABLED=true
NVL_MEDIA_DEDUPLICATION_LOCK_STORE=redis
NVL_MEDIA_DEDUPLICATION_LOCK_SECONDS=30
NVL_MEDIA_DEDUPLICATION_LOCK_WAIT_SECONDS=30
```

### Mutation lock

| Key | Default |
| --- | --- |
| `nvl-media.mutation_lock.enabled` | `true` |
| `nvl-media.locks.mutation.store` | application default cache store |
| `nvl-media.mutation_lock.seconds` | `300` |
| `nvl-media.mutation_lock.wait_seconds` | `30` |

Environment:

```dotenv
NVL_MEDIA_MUTATION_LOCK_ENABLED=true
NVL_MEDIA_MUTATION_LOCK_STORE=redis
NVL_MEDIA_MUTATION_LOCK_SECONDS=300
NVL_MEDIA_MUTATION_LOCK_WAIT_SECONDS=30
```

Multi-node deployments require a central atomic cache store such as Redis. Bulk mutations acquire sorted UUID locks and release them in reverse order.

## File types and media groups

`nvl-media.file_types` is the authoritative canonical extension-to-detected-MIME allowlist:

```php
'file_types' => [
    'jpg' => 'image/jpeg',
    'csv' => ['text/csv', 'text/plain', 'application/csv'],
    'pdf' => 'application/pdf',
],
```

Each value is a MIME string or non-empty list of legitimate aliases. If a filename has an extension, its detected MIME must belong to that extension’s list. A canonical configured extension is inferred only when the source genuinely lacks one.

`nvl-media.group_types` maps extensions into `image`, `document`, `video`, `audio`, `archive`, `code`, or fallback `other`. It controls the `MediaType` classification; it does not replace MIME validation.

Do not add executable or server-handled extensions. Dangerous extensions are rejected anywhere in a multi-extension name even if a MIME mapping is added accidentally.

## Owner and file cleanup

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.delete_media_on_model_delete` | `true` | Host lifecycle-adapter preference; no package owner-delete hook is installed |
| `nvl-media.delete_files_on_media_delete` | `true` | Remove objects after committed media deletion |
| `nvl-media.prevent_deleting_reused_public_media` | `true` | Require explicit force for multiply associated public assets |
| `nvl-media.clean_empty_directories` | `true` | Remove empty local directories after object cleanup |

The host owns model lifecycle hooks: preserve associations on soft deletion if restoration requires them, and call authorized `MediaAttachments::deleteAll()` for intended permanent cleanup. Implement `HasNvlMedia` with its association relation; the contract does not install deletion hooks or a `deletePreservingMedia()` method. Do not disable shared-asset protection as a substitute for correct detach semantics.

## Host association adapters

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.allowed_associable_types` | `[]` | Owner-alias/model-class allowlist retained for an association adapter |
| `nvl-media.associable_mutation_abilities` | `[]` | Owner-reference-to-Gate-ability map retained for an association adapter |

Example:

```php
use App\Models\Article;
use App\Models\Product;

'allowed_associable_types' => [
    Article::class,
    Product::class,
],

'associable_mutation_abilities' => [
    Article::class => 'update',
    Product::class => 'manageMedia',
],
```

Consumers choose and validate their own HTTP owner references; shared Core owner aliases or explicit model classes can identify permitted targets. These configuration keys do not create endpoints or automatically authorize direct native service calls. Resolve the actual `HasNvlMedia` owner, apply the host Gate policy and call `MediaAttachments` with an explicit actor; service ownership and Media authorization remain mandatory.

## File-existence cache

| Key | Default | Meaning |
| --- | --- | --- |
| `nvl-media.cache_file_existence` | `true` | Cache disk existence checks |
| `nvl-media.cache_ttl` | `60` | Existence cache lifetime in seconds |

Mutation paths invalidate affected existence entries. Disable the cache for diagnostic workflows that require an immediate provider read on every check.

## Environment variable index

| Variable | Configuration key |
| --- | --- |
| `NVL_MEDIA_FILESYSTEM_DISK` | `nvl-media.disk` |
| `NVL_MEDIA_S3_USE_ACL_VISIBILITY` | `nvl-media.s3.use_acl_visibility` |
| `NVL_MEDIA_ROOT_FOLDER` | `nvl-media.root_folder` |
| `NVL_MEDIA_SPATIE_PERMISSION_ENABLED` | `nvl-media.authorization.spatie_permission.enabled` |
| `NVL_MEDIA_GLOBAL_ROLES` | `nvl-media.authorization.spatie_permission.global_roles` |
| `NVL_MEDIA_REMOTE_SOURCES_ENABLED` | `nvl-media.sources.remote.enabled` |
| `NVL_MEDIA_REMOTE_CONNECT_TIMEOUT` | `nvl-media.sources.remote.connect_timeout` |
| `NVL_MEDIA_REMOTE_TOTAL_TIMEOUT` | `nvl-media.sources.remote.total_timeout` |
| `NVL_MEDIA_REMOTE_REDIRECTS` | `nvl-media.sources.remote.redirects` |
| `NVL_MEDIA_REMOTE_MAXIMUM_BYTES` | `nvl-media.sources.remote.maximum_bytes` |
| `NVL_MEDIA_REMOTE_VERIFY_CONNECTED_IP` | `nvl-media.sources.remote.verify_connected_ip` |
| `NVL_MEDIA_SCANNER_REQUIRED` | `nvl-media.scanner.required` |
| `NVL_MEDIA_ALLOW_NOOP_SCANNER` | `nvl-media.scanner.allow_noop` |
| `NVL_MEDIA_UNTRUSTED_UPLOADS` | `nvl-media.scanner.untrusted_uploads` |
| `NVL_MEDIA_MULTIPART_ENABLED` | `nvl-media.multipart.enabled` |
| `NVL_MEDIA_MULTIPART_LOCK_STORE` | `nvl-media.locks.multipart.store` |
| `NVL_MEDIA_MULTIPART_LOCK_SECONDS` | `nvl-media.multipart.lock.seconds` |
| `NVL_MEDIA_MULTIPART_LOCK_WAIT_SECONDS` | `nvl-media.multipart.lock.wait_seconds` |
| `NVL_MEDIA_MULTI_NODE` | `nvl-media.deployment.multi_node` |
| `NVL_MEDIA_QUEUE_ENABLED` | `nvl-media.queue.enabled` |
| `NVL_MEDIA_QUEUE_CONNECTION` | `nvl-media.queue.connection` |
| `NVL_MEDIA_QUEUE` | `nvl-media.queue.name` |
| `NVL_MEDIA_IMAGE_DRIVER` | `nvl-media.image_driver` |
| `NVL_MEDIA_WEBP_QUALITY` | `nvl-media.image_formats.webp.quality` |
| `NVL_MEDIA_AVIF_QUALITY` | `nvl-media.image_formats.avif.quality` |
| `NVL_MEDIA_JPEG_QUALITY` | `nvl-media.image_formats.jpg.quality` |
| `NVL_MEDIA_OPTIMIZED_IMAGE_ENABLED` | `nvl-media.output_conversion.enabled` |
| `NVL_MEDIA_IMAGE_MAX_SIZE` | `nvl-media.output_conversion.max_size` |
| `NVL_MEDIA_DEDUPLICATION_LOCK_ENABLED` | `nvl-media.deduplication_lock.enabled` |
| `NVL_MEDIA_DEDUPLICATION_LOCK_STORE` | `nvl-media.locks.deduplication.store` |
| `NVL_MEDIA_DEDUPLICATION_LOCK_SECONDS` | `nvl-media.deduplication_lock.seconds` |
| `NVL_MEDIA_DEDUPLICATION_LOCK_WAIT_SECONDS` | `nvl-media.deduplication_lock.wait_seconds` |
| `NVL_MEDIA_MUTATION_LOCK_ENABLED` | `nvl-media.mutation_lock.enabled` |
| `NVL_MEDIA_MUTATION_LOCK_STORE` | `nvl-media.locks.mutation.store` |
| `NVL_MEDIA_MUTATION_LOCK_SECONDS` | `nvl-media.mutation_lock.seconds` |
| `NVL_MEDIA_MUTATION_LOCK_WAIT_SECONDS` | `nvl-media.mutation_lock.wait_seconds` |

## Production baseline

A supported production configuration should normally include:

```dotenv
NVL_MEDIA_FILESYSTEM_DISK=s3
NVL_MEDIA_S3_USE_ACL_VISIBILITY=false
NVL_MEDIA_MULTI_NODE=true

NVL_MEDIA_SCANNER_REQUIRED=true
NVL_MEDIA_ALLOW_NOOP_SCANNER=false
NVL_MEDIA_UNTRUSTED_UPLOADS=true

NVL_MEDIA_QUEUE_ENABLED=true
NVL_MEDIA_QUEUE_CONNECTION=redis
NVL_MEDIA_QUEUE=media

NVL_MEDIA_DEDUPLICATION_LOCK_STORE=redis
NVL_MEDIA_MUTATION_LOCK_STORE=redis

NVL_MEDIA_REMOTE_SOURCES_ENABLED=false
NVL_MEDIA_MULTIPART_ENABLED=false
```

Enable remote ingestion and multipart independently only where required, then apply their additional gates.

## Related references

- [PHP API](php-api.md)
- [HTTP API](http-api.md)
- [Extension contracts and events](extending.md)
- [S3 and object storage](s3.md)
- [Image variations and queues](images-and-queues.md)
- [Command reference](commands.md)

Use canonical `nvl-media` configuration and `NVL_MEDIA_*` environment names. Rebuild configuration caches and restart workers after changes. Retired bridge or transport settings do not restore the removed APIs; see [upgrade guidance](../UPGRADING.md).
