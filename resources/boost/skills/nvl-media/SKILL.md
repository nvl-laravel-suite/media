---
name: nvl-media
description: Implement, integrate, test, or review nvl/media in Laravel 13. Use for private single-file media, reusable public assets, uploads, multipart object storage, scanning, checksum deduplication, associations, delivery, ranges, localized metadata, image variations, reconciliation, or media authorization.
---

# NVL Media

Treat binary lifecycle, database lifecycle, associations, delivery, and localized metadata as one security boundary. Never expose internal storage paths through public DTOs.

The supported major 5 production profile is PHP 8.3/8.4, Laravel 13, PostgreSQL, S3-compatible private storage with exception-enabled writes, Redis cache/locks/queues, and a real scanner. Multipart is opt-in and requires the recoverable gateway, central session locks, scanner attestation, pruning, and the PostgreSQL/Redis/S3 integration gate. SQLite/local/array/sync are development-compatible but do not prove production concurrency guarantees.

## Read the canonical references

- Resolve canonical references from `vendor/nvl/media/docs/` in a consumer
  application or `docs/` at the root of the public Media repository.
- Use `php-api.md` for exact facade, contract, trait, adder, slot, conversion,
  model-helper, DTO, Action, exception, and transaction contracts.
- Use `http-api.md` for every management/asset route, payload, response, status,
  authorization, and error shape.
- Use `configuration.md` for every configuration key, default, environment
  variable, and production constraint.
- Use `extending.md` before replacing authorization, scanning, search, DNS,
  multipart, library, upload, association, deletion, or reuse boundaries, and
  before consuming lifecycle events.
- Use `images-and-queues.md`, `s3.md`, and `commands.md` for operational rollout.
- Keep these references aligned with source. The package documentation coverage test treats missing public-surface documentation as a failure.

## Choose ownership semantics

- Implement `HasNvlMedia` and use `InteractsWithNvlMedia`. Define slots/conversions in `registerNvlMedia(MediaRegistration $media, ?Media $asset = null)` through `$media->slot()` and `$media->conversion()`; access uploads/mutations/reads through `nvlMedia()` (`add`, `attach`, `detach`, `sync`, `slot`, `first`, `firstUrl`, `all`). Use `ListOwnerMediaSlotsContract` with the explicit actor and `MediaBatchAuthorization` for bounded owner display lists, or the authorized `hasNvlMedia` host scope for existence filtering. `nvlMediaAssociations()` is an internal storage relation; consumers do not load it through relation properties, `load`, `with`, or collection eager loading.
- A model composing Spatie Media keeps Spatie `media()`, `addMedia()` and `registerMediaConversions()` independently. Compose only the new NVL capability there. Deprecated NVL `HasMedia`/`InteractsWithMedia` forward to the same NVL implementation through major 5 and are removed in major 6.
- For application services, inject `MediaLibraryContract` or explicitly import `Nvl\Media\Facades\Media`; never assume a global class alias. The facade delegates to the same Actions and contracts as the trait.
- Treat facade mutations as trusted application-service calls. Authorize user-driven operations with policies or `Media::allows()` before invoking them.
- In jobs and console commands, call `MediaAdder::uploadedBy($actor)` before the terminal upload method when private ownership or uploader-scoped deduplication matters.
- Use a private one-to-one slot when an owner has one replaceable exclusive file.
- Use reusable public media for assets that may have many associations and global checksum deduplication.
- Keep private deduplication inside the configured uploader, tenant, or owner boundary.
- Use the optional Spatie-compatible bridge for cross-owner administrators. Keep global role names explicit; use explicit `nvl.media.manage` or granular `nvl.media.*-any` permission mappings; generic old grants require a deliberate host mapping. Privilege bypasses uploader ownership, not association integrity or quarantine.
- Use `AttachMediaAction`, `DetachMediaAction`, or `ReusePublicMediaAction`; never write association rows directly.
- Attachment and public reuse reload current persisted state under the media
  mutation lock and a database row lock. Recheck availability and public reuse
  visibility in the same transaction as attachment; an older model instance's
  `status` or `is_public` is not authoritative. Caller authorization remains
  required for user-driven operations.

## Read bounded owner lists

- Use `ListOwnerMediaSlotsContract` for many-owner slot display; do not loop single-owner reads or query package models in consumer services.
- Bind an explicit query-free `MediaBatchAuthorization` SQL adapter. Existing imperative `MediaAuthorization` callbacks do not authorize batches. See `extending.md` for the four methods and unsupported-adapter failure.
- Express complete host owner/registered-slot/association/asset View eligibility in `scopeHostAssociations`, using nested SQL predicates. Owner-dependent asset View and attribute-dependent slot declarations need a supported SQL expression or an explicit `MediaBatchReadException`; never treat an empty OwnerBatch as a wildcard or fabricate persisted owner facts.
- Use `hasNvlMedia($slot, $actor, $policy)` on host queries; preserve host columns, global scopes, canonical connections, and base tables independently of Spatie `hasMedia()`.
- Keep canonical collection identity, active associations, available lifecycle, soft deletion, and registered tenant boundaries mandatory. Validate each reloaded owner's registered slots; metadata.slot is not the canonical collection.
- Preserve bounds: at most 100 owners, 20 registered slots, ten items per owner/slot capped by its declaration, 20,000 association rows, and ten requested/fallback locale rows per asset. Bound rows in SQL before hydration, with exact native morph/key pairs.
- Return only the nine approved original-asset item fields and object maps, including requested empty slots and numeric names. Private URLs remain null; obtain Download links separately through existing explicitly authorized delivery.
- Load no variations. Resolve localized title/alt/caption through pure `TranslationResolver` on bounded preloaded rows; full-model translated accessors and `MediaAssetService::resolve` do not preserve this batch query budget.
- Verify fixed total query counts at 1/25/100, dense storage transfer ceilings, nested OR containment, canonical forged-owner denial, supported native drivers, and actual Spatie coexistence.

## Upload and finalize

- Use `UploadMediaAction` for proxied uploads.
- Treat detected bytes, never client MIME/extension/headers/object metadata, as authoritative. Keep `nvl-media.file_types` as `extension => string|list<string>`.
- Keep all materialized uploads on `MediaIngestionPipeline`: dangerous multi-extension rejection, canonical type, size/slot policy, scanner, SVG policy, SHA-256, random canonical storage name, and stored-object verification.
- Keep remote URL ingestion disabled unless the application uses it. When enabled, retain public-address validation, cURL pinning, redirect revalidation, connected-IP attestation, and byte/time limits; test transports may opt out of attestation outside production.
- For direct object storage, use `InitiateMultipartUploadAction`, `SignMultipartPartAction`, `CompleteMultipartUploadAction`, and `AbortMultipartUploadAction`; every mutation accepts the opaque session ID and reloads persisted actor-owned state.
- Keep multipart disabled unless direct uploads are needed. Enabled production sessions require the recoverable S3-compatible gateway, central locks, per-part length/SHA-256, `MediaScanResultData` attestation, pruning, and provider integration tests.
- Bind a real `MediaContentScanner` for production untrusted ingestion.
- Do not make multipart media available before matching size/checksum/type scanner attestation.
- Keep completion idempotent and protect deduplication and per-media/session mutation with central atomic locks.
- `nvlMedia()->add($explicitLocalPath)` deletes that owned source only after root commit; use `nvlMedia()->copy()` or `preservingOriginal()` to retain it.

## Deliver and transform

- Keep management APIs disabled independently of asset delivery.
- Enforce `MediaAuthorization` for viewing, downloading, associating, mutating, deleting, and reusing.
- Support public caching and private signed delivery with GET, HEAD, byte ranges, ETags, expiry, and safe content disposition.
- Generate named variations through `GenerateImageVariationAction`; the action lock, source revision and immutable random object path make retries safe.
- Resolve variation labels with upload, model, slot, global precedence. Persist upload definitions; reject conflicts on a shared deduplicated asset; honor `withoutVariations()`.
- Use `ImageFormat`, `ImageFit`, `ImageCompression`, and `ImagePreset` in published image configuration.
- Use `max_size` with `ImageFit::Max` for proportional, no-upscale outputs; use `ImageFit::Crop` only when exact dimensions are required.
- Keep S3 objects private at rest by default. Public media is a reuse/delivery policy; enable object ACL visibility only for an ACL-enabled bucket.
- Keep queue `retry_after` or SQS visibility timeout above every Media job timeout and use a shared atomic lock cache.
- Store alt text, title, caption, and description through `nvl/translatable`.

## Operate and verify

- Treat tenancy as opt-in. Register every Media owner as a canonical tenant
  resource and activate only after the package adapter verifies the full graph.
- Treat catalog grants as copy availability, never shared ownership. Imported
  files and rows are independent; revocation cannot rewrite committed copies.
- Resume an interrupted adoption only after source/schema repair consistent
  with its immutable mapping. A changed mapping requires restore/new prepare.
- Keep cleanup package-owned and bounded, and verify physical object identity
  before deleting source or imported bytes.

- Run `nvl:media:doctor --production --strict --format=json`. Its persisted-path
  check verifies live Media objects and intentionally excludes soft-deleted
  tombstones whose objects canonical deletion may already have removed.
- Use read-only production inventory with `nvl:media:reconcile --production --orphans`.
- Never clean orphans implicitly. Require `--cleanup-orphans`, an age threshold, and `--force` in production; retain age-unknown objects.
- Schedule `nvl:media:multipart:prune` wherever multipart is enabled with persisted sessions and the recoverable gateway.
- Use `nvl:media:regenerate` and `nvl:media:migrate-disk` for controlled maintenance.
- Test public reuse, private isolation, root commit/rollback, concurrent replacement/variation/finalization, scanner failure, SVG safety, SSRF/DNS pinning, temporary-file release, signed expiry, Range/HEAD behavior, multipart recovery, missing storage, localized metadata, and orphan reconciliation.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared owner identities

- Declare owner class lists in `nvl-core.owners` and reference model classes in `media` capability configuration. Laravel `getMorphClass()` supplies the host-authored stored identity; declarations do not add global host morph mappings.
- Owners implement HasNvlMedia and use InteractsWithNvlMedia. Preserve collection/slot declarations and authorize the configured mutation ability.
- Keep the package allowlist and authorization independent of Core registration. Never authorize a model merely because Core knows it.
- Preserve resolvers, handlers and authorization. Legacy aliases require agreement with native `getMorphClass()` and are removed in major 6; Doctor reports mismatches and stored identity drift without conversion.
- If the host changes its morph map, explicitly reconcile reviewed package-owned columns and affected host relations before cutover. Core and package capability registration never mutate the host morph map or rewrite stored values.

## Shared diagnostics and queue inheritance

- Run `php artisan nvl:doctor --strict --format=json` for all loaded package checks; retain `nvl:media:doctor` for Media-specific production diagnostics.
- Resolve Media queue connection from explicit `nvl-media.queue.connection`, then `nvl-core.queue.connection`, then effective `queue.default`. Null inherits; empty strings are invalid; explicit sync remains synchronous.
- Keep `NVL_MEDIA_QUEUE_CONNECTION` in config files, rebuild cached configuration, and restart workers after queue changes.


## Shared infrastructure options

- Resolve queue names through `nvl-media.queue.name`, Core's queue name, then the selected Laravel connection's queue. Specify `NVL_MEDIA_QUEUE=media` when deployment workers consume that dedicated name.
- Use `locks.store` for a shared cache store and `locks.mutation.store`, `locks.deduplication.store`, or `locks.multipart.store` for explicit independent overrides. Preserve operation duration/wait settings; historical store keys are supported for one major cycle and reported through Doctor.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-media.tables.*`, connections through `nvl-media.connection` with Core/Laravel inheritance. Defaults use `nvl_media_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=media --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Cache and lock ownership

Mutation, deduplication, and multipart locks use `nvl:media:mutation:`, `nvl:media:deduplication:`, and `nvl:media:multipart:`. File existence entries use `nvl:media:file-existence:` instead of `media_exists:`. Invalidation touches only the owned entry; generic host keys are never read, copied, or removed.

Drain old mutation workers and maintenance processes, then wait for their outstanding lock leases to end before starting the new major across all nodes. Running old and new lock prefixes concurrently would create independent serialization domains. Restart workers after cutover; preserve host-selected stores and keys, and do not flush a shared cache to remove old NVL entries.

## Canonical configuration ownership

- Read/write `nvl-media` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.
