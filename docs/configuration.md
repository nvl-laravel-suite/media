# Configuration reference

Publish the package configuration with:

```bash
php artisan vendor:publish --tag=media-config
```

Keep environment reads in `config/media.php`, then use `php artisan config:cache` for production. Run `php artisan nvl:media:doctor --production --strict` against the cached configuration.

The values below are the shipped 1.x defaults. Application-published configuration is recursively merged with package defaults so newly added nested safety settings continue to exist after an upgrade; review `UPGRADING.md` whenever publishing or merging a new version.

## Routes

| Key | Default | Meaning |
| --- | --- | --- |
| `media.routes.api_enabled` | `false` | Register the management API |
| `media.routes.api_prefix` | `api/v1` | Prefix surrounding the package `/media` routes |
| `media.routes.api_middleware` | `['api']` | Middleware wrapping the route file |
| `media.routes.management_middleware` | `['auth', 'throttle:60,1']` | Middleware applied to every management endpoint |
| `media.routes.assets_enabled` | `true` | Register public and signed private delivery routes |
| `media.routes.assets_prefix` | `media` | Asset-route URI prefix |

The management API and asset delivery are independent. Production doctor fails when writable management routes lack authentication/authorization middleware.

See [HTTP API](http-api.md).

## Authorization

`media.authorization.spatie_permission` is an optional duck-typed bridge. The package does not require `spatie/laravel-permission`.

| Key | Default | Meaning |
| --- | --- | --- |
| `enabled` | `true` | Consult compatible role/permission methods when present |
| `global_roles` | `[]` | Roles granting every media ability |
| `global_permission` | `media.manage` | Permission granting every media ability |
| `ability_permissions.list_all` | `media.view-any` | Cross-owner library listing |
| `ability_permissions.view` | `media.view-any` | Cross-owner view |
| `ability_permissions.download` | `media.download-any` | Cross-owner download |
| `ability_permissions.associate` | `media.associate-any` | Cross-owner attach/detach |
| `ability_permissions.mutate` | `media.update-any` | Cross-owner update/replace/move/variation work |
| `ability_permissions.delete` | `media.delete-any` | Cross-owner delete |
| `ability_permissions.reuse` | `media.reuse-any` | Cross-owner public reuse |

Environment:

```dotenv
MEDIA_SPATIE_PERMISSION_ENABLED=true
MEDIA_GLOBAL_ROLES=admin,super-admin
```

Role names are empty by default so an installation cannot silently elevate an existing role. Missing methods, roles, permissions, or guard compatibility fail closed. Replace `MediaAuthorization` when the application needs a different policy model.

## Migrations

| Key | Default | Meaning |
| --- | --- | --- |
| `media.migrations.enabled` | `true` | Load package migrations automatically |

Set this to `false` only for controlled adoption of an existing schema. Published migrations are still available through the `media-migrations` publish tag.

## Storage

| Key | Default | Meaning |
| --- | --- | --- |
| `media.disk` | `MEDIA_FILESYSTEM_DISK`, then `FILESYSTEM_DISK`, then `local` | Default media disk |
| `media.s3.use_acl_visibility` | `false` | Apply per-object ACL visibility on intentionally ACL-enabled S3 buckets |
| `media.root_folder` | `media` | Mandatory storage prefix and reconciliation boundary |
| `media.default_path` | `misc` | Fallback folder template |
| `media.conversions_folder` | `conversions` | Variation subdirectory |
| `media.allowed_disks` | `['local', 'public']` | Security allowlist for HTTP and direct uploads |

Environment:

```dotenv
MEDIA_FILESYSTEM_DISK=s3
MEDIA_S3_USE_ACL_VISIBILITY=false
MEDIA_ROOT_FOLDER=media
```

Production S3-compatible disks should use `throw=true` and private objects at rest. Public media is delivered publicly by package policy; it does not require a `public-read` object ACL.

Every resolved path is restricted beneath `media.root_folder`. Never set it to an empty string, `/`, a bucket root intended for unrelated objects, or an untrusted value.

See [S3 and object storage](s3.md).

## Ordinary upload limits

| Key | Default | Unit |
| --- | --- | --- |
| `media.max_file_size` | `10 * 1024 * 1024` | Bytes per file |
| `media.max_files_per_upload` | `10` | Files per management upload request |

Slot-specific `maxFileSize()` may further restrict an upload. Source boundaries and the authoritative Action both enforce applicable limits.

## Remote sources

Remote URL ingestion is opt-in and has no effect on request, local, disk, string, or base64 uploads.

| Key | Default | Meaning |
| --- | --- | --- |
| `media.sources.remote.enabled` | `false` | Enable HTTP/HTTPS URL sources |
| `media.sources.remote.allowed_ports` | `[80, 443]` | Destination ports accepted on every hop |
| `media.sources.remote.connect_timeout` | `5` | cURL connect timeout in seconds |
| `media.sources.remote.total_timeout` | `30` | Complete request timeout in seconds |
| `media.sources.remote.redirects` | `5` | Maximum manually validated redirects |
| `media.sources.remote.maximum_bytes` | `10 * 1024 * 1024` | Streamed download byte ceiling |
| `media.sources.remote.verify_connected_ip` | `true` | Require the connected peer to match a validated pinned IP |

Environment:

```dotenv
MEDIA_REMOTE_SOURCES_ENABLED=false
MEDIA_REMOTE_CONNECT_TIMEOUT=5
MEDIA_REMOTE_TOTAL_TIMEOUT=30
MEDIA_REMOTE_REDIRECTS=5
MEDIA_REMOTE_MAXIMUM_BYTES=10485760
MEDIA_REMOTE_VERIFY_CONNECTED_IP=true
```

When enabled, every URL and redirect is restricted to HTTP/HTTPS, credential-free URLs, allowed ports, and public A/AAAA results. Requests are pinned to the validated result set while preserving hostname, TLS SNI, and certificate verification. Automatic redirects are disabled. Retries remain inside the validated IP set.

The connected-IP check may be disabled only for deterministic test transports or a controlled custom boundary. Production doctor rejects it when remote ingestion is enabled.

## Integrity

| Key | Default | Meaning |
| --- | --- | --- |
| `media.integrity.verification` | `required` | Stored size and SHA-256 verification policy |
| `media.integrity.provider_checksum` | `prefer` | Prefer trustworthy provider checksum metadata |
| `media.integrity.streamed_readback_fallback` | `true` | Stream-read the object when provider attestation is unavailable |

The production invariant is that every live row references a verified object. Do not disable streamed fallback unless the selected gateway provides equivalent trustworthy SHA-256 and size attestation and the application has validated that behavior.

## Scanner

| Key | Default | Meaning |
| --- | --- | --- |
| `media.content_scanner` | `NullMediaContentScanner::class` | Container implementation of `MediaContentScanner` |
| `media.scanner.required` | `false` | Require scanner policy |
| `media.scanner.allow_noop` | `false` | Permit the no-op scanner outside strict production checks |
| `media.scanner.untrusted_uploads` | `true` | Mark incoming content as untrusted for diagnostics |
| `media.svg_scanning` | `true` | Run built-in structural SVG scanning |

Environment:

```dotenv
MEDIA_SCANNER_REQUIRED=true
MEDIA_ALLOW_NOOP_SCANNER=false
MEDIA_UNTRUSTED_UPLOADS=true
```

The built-in SVG scanner and external malware scanner solve different problems. Keep SVG scanning enabled even when a malware scanner is bound. Production doctor fails when untrusted uploads use the no-op scanner.

## Multipart

Direct multipart uploads are disabled by default.

| Key | Default | Meaning |
| --- | --- | --- |
| `media.multipart.enabled` | `false` | Enable persisted provider sessions |
| `media.multipart.gateway` | `S3MultipartUploadGateway::class` | Provider implementation |
| `media.multipart.required_scan` | `true` | Require post-upload scanner attestation |
| `media.multipart.session_minutes` | `60` | Session lifetime |
| `media.multipart.minimum_part_size` | `5 MiB` | Non-final minimum part size |
| `media.multipart.maximum_part_size` | `5 GiB` | Maximum part size |
| `media.multipart.maximum_parts` | `10,000` | Maximum part count |
| `media.multipart.maximum_size` | `5 GiB` | Maximum complete object size |
| `media.multipart.lock.store` | application default | Cache store for session locks |
| `media.multipart.lock.seconds` | `300` | Lock lease |
| `media.multipart.lock.wait_seconds` | `30` | Maximum lock wait |

Environment:

```dotenv
MEDIA_MULTIPART_ENABLED=false
MEDIA_MULTIPART_LOCK_STORE=redis
MEDIA_MULTIPART_LOCK_SECONDS=300
MEDIA_MULTIPART_LOCK_WAIT_SECONDS=30
```

Enabling multipart in production requires a recoverable gateway, central atomic lock store, persisted session migration, real scanner attestation, expired-session pruning, provider integration tests, and a clean strict production doctor. Every completed object remains `pending_scan` until an exact `MediaScanResultData` attestation makes it available.

## Query

| Key | Default | Meaning |
| --- | --- | --- |
| `media.query.maximum_page_size` | `100` | Hard cap for management and facade pagination |
| `media.query.search_driver` | `PortableMediaSearchDriver::class` | Implementation of `MediaSearchDriver` |

The portable driver performs moderate-dataset substring and JSON search. Bind or configure a PostgreSQL full-text or external implementation for larger search workloads without replacing `MediaQueryService`.

## Reconciliation

| Key | Default | Meaning |
| --- | --- | --- |
| `media.reconciliation.orphan_age_minutes` | `1440` | Minimum orphan age |
| `media.reconciliation.restrict_to_root` | `true` | Refuse inventory outside `media.root_folder` |
| `media.reconciliation.page_size` | `500` | Object inventory page size |

Keep root restriction enabled. Cleanup remains opt-in at the command boundary and requires `--force` in production. Objects whose adapter cannot report reliable age remain report-only.

## Deployment topology

| Key | Default | Meaning |
| --- | --- | --- |
| `media.deployment.multi_node` | `false` | Tell doctor to require shared production infrastructure |

Environment:

```dotenv
MEDIA_MULTI_NODE=true
```

This flag does not create a cluster. It strengthens diagnostics for central locks, durable queues, and storage assumptions.

## Queues

| Key | Default | Meaning |
| --- | --- | --- |
| `media.queue.enabled` | `true` | Permit background variation workflows |
| `media.queue.connection` | `MEDIA_QUEUE_CONNECTION`, then `QUEUE_CONNECTION`, then `sync` | Queue connection |
| `media.queue.name` | `media` | Default queue |
| `media.queue.jobs.generate.tries` | `3` | Variation job attempts |
| `media.queue.jobs.generate.timeout` | `60` | Variation timeout in seconds |
| `media.queue.jobs.generate.backoff` | `[10, 30, 90]` | Retry backoff seconds |
| `media.queue.jobs.generate.unique_for` | `1800` | Queue uniqueness window |
| `media.queue.jobs.dispatch.*` | Same as generate | Dispatch job policy |
| `media.queue.jobs.regenerate.tries` | `1` | Regeneration batch attempts |
| `media.queue.jobs.regenerate.timeout` | `60` | Regeneration timeout |
| `media.queue.jobs.regenerate.backoff` | `[60]` | Regeneration retry backoff |

Environment:

```dotenv
MEDIA_QUEUE_ENABLED=true
MEDIA_QUEUE_CONNECTION=redis
MEDIA_QUEUE=media
```

Production background work requires a durable queue. Queue `retry_after` or provider visibility timeout must be greater than every corresponding media job timeout. Queue uniqueness is an optimization; media mutation locks and revision checks remain the correctness boundary.

See [Image variations and queues](images-and-queues.md).

## Image driver and constraints

| Key | Default | Meaning |
| --- | --- | --- |
| `media.image_driver` | resolved from `MEDIA_IMAGE_DRIVER`, default `gd` | `gd`, `imagick`, or `vips` |
| `media.image_constraints.max_width` | `4096` | HTTP dimension rule maximum |
| `media.image_constraints.max_height` | `4096` | HTTP dimension rule maximum |
| `media.optimization.skip_formats` | `['svg', 'gif']` | Original formats not optimized |

Environment:

```dotenv
MEDIA_IMAGE_DRIVER=gd
```

The runtime must provide the selected driver and every encoder required by enabled output formats. Doctor checks the configured driver and encoders.

## Image format profiles

`media.image_formats` defines default compression and quality by output format:

| Format | Compression | Quality |
| --- | --- | --- |
| WebP | lossy | `MEDIA_WEBP_QUALITY`, default `82` |
| AVIF | lossy | `MEDIA_AVIF_QUALITY`, default `60` |
| JPEG | lossy | `MEDIA_JPEG_QUALITY`, default `85` |
| PNG | lossless | `100` |

These values are application defaults, not universal visual targets. Validate them against representative images.

## Named variations

| Key | Default | Meaning |
| --- | --- | --- |
| `media.auto_generate_variations` | `true` | Dispatch enabled named definitions after committed uploads |
| `media.image_variation_presets` | `thumb`, `small`, `medium` | Global named definitions |
| `media.output_conversion.enabled` | `true` | Generate the `optimized` output |
| `media.output_conversion.format` | WebP | Optimized format |
| `media.output_conversion.compression` | lossy | Optimized compression |
| `media.output_conversion.max_size` | `MEDIA_IMAGE_MAX_SIZE`, default `1200` | Longest-edge bound |
| `media.output_conversion.fit` | max | Preserve aspect ratio and never upscale |
| `media.output_conversion.skip_formats` | `['svg', 'gif']` | Formats excluded from optimized output |
| `media.variation_naming.pattern` | `{basename}-{label}.{extension}` | Stored variation key pattern |

Environment:

```dotenv
MEDIA_OPTIMIZED_IMAGE_ENABLED=true
MEDIA_IMAGE_MAX_SIZE=1200
```

Supported naming tokens are `{basename}`, `{label}`, `{width}`, `{height}`, and `{extension}`. Labels and final paths are validated. Changing the naming pattern requires controlled regeneration.

Dynamic transformation query parameters are unsupported. Only persisted named labels may be delivered.

## Asset delivery

| Key | Default | Meaning |
| --- | --- | --- |
| `media.temporary_url_lifetime` | `5` | Default temporary URL lifetime in minutes |
| `media.assets.public_route_name` | `media.assets.show` | Public route name |
| `media.assets.private_route_name` | `media.private.show` | Signed private route name |
| `media.assets.public_route_middleware` | `['throttle:120,1']` | Public asset middleware |
| `media.assets.private_route_middleware` | `[]` | Extra private asset middleware |
| `media.assets.private_owner_fallback` | `system` | Owner token for private media lacking uploader identity |
| `media.assets.signed_url_lifetime` | `5` | Signed route lifetime in minutes |
| `media.assets.public_cache_control` | `public, max-age=31536000, immutable` | Versioned public response cache header |
| `media.assets.private_cache_control` | `private, max-age=0, no-store` | Private response cache header |
| `media.assets.remote_public_delivery` | `route` | Route-backed remote object delivery |
| `media.assets.allowed_parameters` | `['v']` | Asset query allowlist |

Environment:

```dotenv
MEDIA_ASSET_SIGNED_URL_LIFETIME=5
MEDIA_REMOTE_PUBLIC_DELIVERY=route
```

Keep private URLs short-lived and use package URL builders. The `v` parameter selects a stored named variation; it is included in signed private URLs. Public URLs generated by the package include an authoritative content version. Unversioned public route requests are forced to revalidate and never receive the immutable policy.

## Display filename and duplication

| Key | Default | Meaning |
| --- | --- | --- |
| `media.hash_filenames` | `true` | Compatibility setting; storage identities remain cryptographically random in hardened 1.x |
| `media.transliterate` | `false` | Transliterate non-ASCII display filenames during sanitization |
| `media.allow_duplicates` | `false` | Global default for caller-requested duplicate records |
| `media.deduplication.allow_anonymous_private` | `false` | Permit anonymous private digest reuse |

`media.hash_filenames` must not be treated as a switch back to display-derived object names. Hardened ingestion always generates an opaque object name with only the validated canonical extension. `usingFileName()` affects presentation metadata.

Private deduplication is scoped by uploader type and identifier. Anonymous private uploads create unique records by default. Public shared slots deduplicate by validated content digest, disk, and visibility.

## Atomic locks

### Digest lock

| Key | Default |
| --- | --- |
| `media.deduplication_lock.enabled` | `true` |
| `media.deduplication_lock.store` | application default cache store |
| `media.deduplication_lock.seconds` | `30` |
| `media.deduplication_lock.wait_seconds` | `30` |

Environment:

```dotenv
MEDIA_DEDUPLICATION_LOCK_ENABLED=true
MEDIA_DEDUPLICATION_LOCK_STORE=redis
MEDIA_DEDUPLICATION_LOCK_SECONDS=30
MEDIA_DEDUPLICATION_LOCK_WAIT_SECONDS=30
```

### Mutation lock

| Key | Default |
| --- | --- |
| `media.mutation_lock.enabled` | `true` |
| `media.mutation_lock.store` | application default cache store |
| `media.mutation_lock.seconds` | `300` |
| `media.mutation_lock.wait_seconds` | `30` |

Environment:

```dotenv
MEDIA_MUTATION_LOCK_ENABLED=true
MEDIA_MUTATION_LOCK_STORE=redis
MEDIA_MUTATION_LOCK_SECONDS=300
MEDIA_MUTATION_LOCK_WAIT_SECONDS=30
```

Multi-node deployments require a central atomic cache store such as Redis. Bulk mutations acquire sorted UUID locks and release them in reverse order.

## File types and media groups

`media.file_types` is the authoritative canonical extension-to-detected-MIME allowlist:

```php
'file_types' => [
    'jpg' => 'image/jpeg',
    'csv' => ['text/csv', 'text/plain', 'application/csv'],
    'pdf' => 'application/pdf',
],
```

Each value is a MIME string or non-empty list of legitimate aliases. If a filename has an extension, its detected MIME must belong to that extension’s list. A canonical configured extension is inferred only when the source genuinely lacks one.

`media.group_types` maps extensions into `image`, `document`, `video`, `audio`, `archive`, `code`, or fallback `other`. It controls the `MediaType` classification; it does not replace MIME validation.

Do not add executable or server-handled extensions. Dangerous extensions are rejected anywhere in a multi-extension name even if a MIME mapping is added accidentally.

## Owner and file cleanup

| Key | Default | Meaning |
| --- | --- | --- |
| `media.delete_media_on_model_delete` | `true` | Clean media on force deletion of a media-enabled owner |
| `media.delete_files_on_media_delete` | `true` | Remove objects after committed media deletion |
| `media.prevent_deleting_reused_public_media` | `true` | Require explicit force for multiply associated public assets |
| `media.clean_empty_directories` | `true` | Remove empty local directories after object cleanup |

Soft-deleting an owner preserves associations for restoration. `deletePreservingMedia()` bypasses owner-triggered cleanup deliberately. Do not disable shared-asset protection as a substitute for correct detach semantics.

## Associable API security

| Key | Default | Meaning |
| --- | --- | --- |
| `media.allowed_associable_types` | `[]` | Exact model class allowlist for HTTP attach/detach/reorder |
| `media.associable_mutation_abilities` | `[]` | Model-class-to-Gate-ability map; default ability is `update` |

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

The HTTP payload uses the exact allowlisted class name. The target must implement `HasMedia`, exist, and pass its Gate ability. An empty allowlist denies every association mutation.

## File-existence cache

| Key | Default | Meaning |
| --- | --- | --- |
| `media.cache_file_existence` | `true` | Cache disk existence checks |
| `media.cache_ttl` | `60` | Existence cache lifetime in seconds |

Mutation paths invalidate affected existence entries. Disable the cache for diagnostic workflows that require an immediate provider read on every check.

## Environment variable index

| Variable | Configuration key |
| --- | --- |
| `MEDIA_FILESYSTEM_DISK` | `media.disk` |
| `MEDIA_S3_USE_ACL_VISIBILITY` | `media.s3.use_acl_visibility` |
| `MEDIA_ROOT_FOLDER` | `media.root_folder` |
| `MEDIA_SPATIE_PERMISSION_ENABLED` | `media.authorization.spatie_permission.enabled` |
| `MEDIA_GLOBAL_ROLES` | `media.authorization.spatie_permission.global_roles` |
| `MEDIA_REMOTE_SOURCES_ENABLED` | `media.sources.remote.enabled` |
| `MEDIA_REMOTE_CONNECT_TIMEOUT` | `media.sources.remote.connect_timeout` |
| `MEDIA_REMOTE_TOTAL_TIMEOUT` | `media.sources.remote.total_timeout` |
| `MEDIA_REMOTE_REDIRECTS` | `media.sources.remote.redirects` |
| `MEDIA_REMOTE_MAXIMUM_BYTES` | `media.sources.remote.maximum_bytes` |
| `MEDIA_REMOTE_VERIFY_CONNECTED_IP` | `media.sources.remote.verify_connected_ip` |
| `MEDIA_SCANNER_REQUIRED` | `media.scanner.required` |
| `MEDIA_ALLOW_NOOP_SCANNER` | `media.scanner.allow_noop` |
| `MEDIA_UNTRUSTED_UPLOADS` | `media.scanner.untrusted_uploads` |
| `MEDIA_MULTIPART_ENABLED` | `media.multipart.enabled` |
| `MEDIA_MULTIPART_LOCK_STORE` | `media.multipart.lock.store` |
| `MEDIA_MULTIPART_LOCK_SECONDS` | `media.multipart.lock.seconds` |
| `MEDIA_MULTIPART_LOCK_WAIT_SECONDS` | `media.multipart.lock.wait_seconds` |
| `MEDIA_MULTI_NODE` | `media.deployment.multi_node` |
| `MEDIA_QUEUE_ENABLED` | `media.queue.enabled` |
| `MEDIA_QUEUE_CONNECTION` | `media.queue.connection` |
| `MEDIA_QUEUE` | `media.queue.name` |
| `MEDIA_IMAGE_DRIVER` | `media.image_driver` |
| `MEDIA_WEBP_QUALITY` | `media.image_formats.webp.quality` |
| `MEDIA_AVIF_QUALITY` | `media.image_formats.avif.quality` |
| `MEDIA_JPEG_QUALITY` | `media.image_formats.jpg.quality` |
| `MEDIA_OPTIMIZED_IMAGE_ENABLED` | `media.output_conversion.enabled` |
| `MEDIA_IMAGE_MAX_SIZE` | `media.output_conversion.max_size` |
| `MEDIA_ASSET_SIGNED_URL_LIFETIME` | `media.assets.signed_url_lifetime` |
| `MEDIA_REMOTE_PUBLIC_DELIVERY` | `media.assets.remote_public_delivery` |
| `MEDIA_DEDUPLICATION_LOCK_ENABLED` | `media.deduplication_lock.enabled` |
| `MEDIA_DEDUPLICATION_LOCK_STORE` | `media.deduplication_lock.store` |
| `MEDIA_DEDUPLICATION_LOCK_SECONDS` | `media.deduplication_lock.seconds` |
| `MEDIA_DEDUPLICATION_LOCK_WAIT_SECONDS` | `media.deduplication_lock.wait_seconds` |
| `MEDIA_MUTATION_LOCK_ENABLED` | `media.mutation_lock.enabled` |
| `MEDIA_MUTATION_LOCK_STORE` | `media.mutation_lock.store` |
| `MEDIA_MUTATION_LOCK_SECONDS` | `media.mutation_lock.seconds` |
| `MEDIA_MUTATION_LOCK_WAIT_SECONDS` | `media.mutation_lock.wait_seconds` |

## Production baseline

A supported production configuration should normally include:

```dotenv
MEDIA_FILESYSTEM_DISK=s3
MEDIA_S3_USE_ACL_VISIBILITY=false
MEDIA_MULTI_NODE=true

MEDIA_SCANNER_REQUIRED=true
MEDIA_ALLOW_NOOP_SCANNER=false
MEDIA_UNTRUSTED_UPLOADS=true

MEDIA_QUEUE_ENABLED=true
MEDIA_QUEUE_CONNECTION=redis
MEDIA_QUEUE=media

MEDIA_DEDUPLICATION_LOCK_STORE=redis
MEDIA_MUTATION_LOCK_STORE=redis

MEDIA_REMOTE_SOURCES_ENABLED=false
MEDIA_MULTIPART_ENABLED=false
```

Enable remote ingestion and multipart independently only where required, then apply their additional gates.

## Related references

- [PHP API](php-api.md)
- [HTTP API](http-api.md)
- [Extension contracts and events](extending.md)
- [S3 and object storage](s3.md)
- [Image variations and queues](images-and-queues.md)
- [Command reference](commands.md)
