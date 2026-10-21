# Upgrading NVL Media

## 6.0.1 consolidation patch

This package requires Core 6.0.1 or later and, when installed, Ownership 6.0.1 or later. Ownership remains optional. Update the installed NVL dependency closure together so Composer can select compatible patches.

This patch introduces no schema migration or data conversion. Keep existing model subclasses, ownership declarations, adoption cursors and queued payloads. Existing source transactions, immutable owner checks and legacy exception contracts are retained. Deploy with the normal configuration-cache rebuild and worker restart; a completed adoption does not need to be repeated.

## Major 6 ownership rollout

Support configured Media subclasses with native columns or exclusive pivots. Derive associations, variations and translations from the canonical asset; fence multipart and slot receipts, private delivery, storage copies and worker restoration while preserving asset UUIDs and original tenant IDs.

Upgrade the selected NVL dependency closure together to `^6.0`. Ordinary unowned hosts retain the default unscoped declarations and need no ownership context. For isolation, use the installed Ownership README and UPGRADING guide, exact configured subclasses and reviewed adoption before accepting traffic. Disabling or removing Ownership is not rollback; Core retains durable denial guards. Keep existing tenant IDs and migration history. The explicit Tenancy bridge remains deprecated through major 6; its retirement is a separate reviewed migration no earlier than major 7.


## Per-disk folders and visibility defaults (5.1.0)

The new `root_folders` map defaults to empty; `public_disk` and `private_disk` default to null. Existing disk and folder behavior remains unchanged until configured. An explicit empty per-disk folder omits the base prefix. See [Storage configuration](docs/configuration.md#storage).

Keep roots aligned with existing objects: changing a prefix does not move files. Configure a separate destination disk/prefix and use the migration dry run, verified move and reconciliation before cutover. Equal prefixes retain original paths; different prefixes relocate originals and variations. Drain multipart sessions, rebuild configuration caches and restart workers before applying a layout change. Whole-disk orphan cleanup is refused; tenant cleanup remains bounded to its active partition.

For originals directly at a standalone disk root, both the selected root prefix and logical folder must be empty; `default_path => ''` is now honored. Variations and enabled tenant partitions retain their subfolders. Refresh the published `nvl-media` agent skill after upgrading; review local edits before using `vendor:publish --tag=nvl-media-skills --force`, or let the Suite skill manager safely update its owned copy.

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. The PHP 8.4/Laravel 13 local Dagger release gate and fresh public Composer installation passed for 5.0.0. Additional compatibility legs need separate evidence; hosts must verify their own adoption.


## Consumer contracts and upload fakes (major 5)

The library contract and facade now declare `MediaUploadBuilder` for `add`, `copy`,
`fromRequest`, `fromUrl`, `fromBase64`, `fromString`, `fromBinary`, and `fromDisk`.
The real `MediaLibrary` remains `final readonly` and returns `MediaAdder`; its
constructor and behavior are unchanged. `MediaAdder` implements the new interface
without changing its constructor, setters, or upload pipeline.

Update application declarations that receive the contract's return value to accept
`MediaUploadBuilder`. Code deliberately using `MediaAdder::$pendingSlot`, protected
extension hooks, or other concrete-only details must retain an explicit real-adder
dependency; those properties/hooks are outside the method-only builder contract.
Existing host implementations returning `MediaAdder` remain covariant. Replacement
builders must implement all eighteen fluent setters and the three native terminals.

Selected Action defaults and public extension defaults preserve early host bindings;
the library and catalog importer retain their scoped lifetime. The six existing
Action interfaces, including the owner-slot reader, are reused. Real Action method
bodies, constructors, defaults, and final/readonly qualifiers are retained.

For tests, install `FakeMediaLibrary::fake($app)` before constructing consumers.
Script each source method with a fresh `FakeMediaUploadBuilder`, then independently
script its terminal results. Builder reuse across library invocations fails; repeated
terminals consume new FIFO entries. Exceptions and exhausted scripts are recorded.
See [the complete fake API](docs/php-api.md#replaceable-workflows-and-scripted-uploads)
for argument snapshots, container ownership, and scoped/facade-cache behavior.


## Batched slot reads

`ListOwnerMediaSlotsContract` and `hasNvlMedia()` require an explicitly bound `MediaBatchAuthorization` SQL adapter.
Existing imperative authorization bindings remain intact and do not automatically authorize batches. Implement all
four query-free methods, including complete host owner/slot/association/asset View eligibility; fail explicitly when
owner-dependent host eligibility cannot be represented in SQL. Private batch URLs are null; existing authorized
Download delivery remains the link source. The reader uses canonical collections, validates each reloaded owner's
slot declarations, caps results at ten items per owner/slot, and does not load variations.

## Tenant ownership cutover

Tenancy is opt-in and disabled by default. Before enabling it, register every
Media owner as a Foundation tenant resource, take a pre-cutover database and
object-store backup, review every legacy root assignment/split, and run the
coordinator's expand, bounded backfill, verify, and activate phases in
maintenance. Keep the original persisted storage path authoritative.

Source or schema repairs that preserve the prepared mapping may resume the same
run. A changed mapping must not overwrite an interrupted run: restore the
backup or finish recovery, then create a new reviewed preparation. Tenant
columns are not a reversible rollback after duplicate business keys or copied
objects exist. Grants affect future catalog availability only; imported assets
are independent and cleanup must never remove another tenant's copy.

## Upgrading to 1.0

Version 1.0 uses UUID media records, string-compatible morph identifiers, explicit lifecycle and visibility, localized metadata rows, and separate management and delivery routes.

1. Set `nvl-media.migrations.enabled=false` for an existing schema.
2. Run `php artisan nvl:media:doctor --strict --format=json`.
3. Stage the legacy Spatie-style table under a non-canonical name, run `php artisan nvl:media:adopt-spatie --source=... --format=json`, resolve every mapping/path error, then repeat with `--apply`. Pass `--translations` and `--variations` for staged companion tables.
4. Register owners with `HasMedia` and `InteractsWithMedia`.
5. Bind `MediaAuthorization`, a production `MediaContentScanner`, and a `MultipartUploadGateway` when needed.
6. Enable asset delivery independently of management APIs.
7. Run read-only `php artisan nvl:media:reconcile --production` before and after cutover.

If existing `folder` values are already complete disk-relative paths, set `NVL_MEDIA_ROOT_FOLDER=` during in-place adoption. Leaving the default `media` prefix would point every imported row at a different object. Keep the empty root only for a dedicated disk, or move objects through `nvl:media:migrate-disk` into the prefixed layout. `nvl:media:doctor` samples persisted rows and flags this mismatch.

Treat any strict `storage.persisted_paths` failure as a data incident. Diagnose
and restore missing objects before considering association or row removal; do
not schedule `nvl:media:reconcile --cleanup-orphans` as an adoption shortcut.

### Image and queue configuration

The v1 configuration uses `nvl-media.queue.enabled`, `nvl-media.queue.connection`, `nvl-media.queue.name`, and `nvl-media.queue.jobs.*`. Remove the pre-v1 flat `queue_conversions`, `queue_connection`, and `queue_name` keys.

Image presets now accept `ImageFit`, `ImageFormat`, and `ImageCompression` enum values. `max_size` with `ImageFit::Max` preserves aspect ratio and never upscales. The built-in `optimized` output is bounded to 1200 pixels.

S3 omits per-object ACLs by default for Bucket Owner Enforced compatibility. Set `NVL_MEDIA_S3_USE_ACL_VISIBILITY=true` only for an ACL-enabled bucket. Changing `nvl-media.variation_naming.pattern` changes derived paths and requires controlled regeneration.

Preserve identifiers and verify row counts, checksums, associations, physical objects, signatures, and rollback before removing old code.

## Upgrading to the production-hardened 2.x release

The clean v1 schema includes immutable variation paths, persisted upload-specific definitions/listing indexes, and server-owned multipart sessions directly in its create migrations.

1. Require `ext-curl`, deploy the new code, and run `php artisan migrate` before enabling ingestion.
2. Merge the new `authorization`, `owner_slots`, `sources.remote`, `integrity`, `mutation_lock`, `multipart`, `query`, `reconciliation`, and `deployment` configuration groups.
3. Change custom `nvl-media.file_types` entries to `extension => string|list<string>` where a canonical extension has legitimate MIME aliases. Existing string entries remain valid.
4. Configure every production disk with `throw=true`, use Redis-backed mutation/deduplication locks and durable queues, and keep queue `retry_after` or SQS visibility timeout above every Media job timeout.
5. Bind a real `MediaContentScanner` whenever untrusted uploads are accepted. `nvl:media:doctor --production` fails closed on the no-op scanner.
6. Keep multipart disabled unless direct uploads are required. When enabled, configure a recoverable gateway, Redis-backed locks, scanner attestation, expired-session pruning, and the production integration gate.
7. Run `php artisan nvl:media:doctor --production --strict --format=json`, followed by read-only `php artisan nvl:media:reconcile --production --orphans`.
8. Remote URL ingestion is now opt-in. Set `NVL_MEDIA_REMOTE_SOURCES_ENABLED=true` only where it is used, then canary it separately after the ordinary upload path.
9. If the application uses Spatie Permission, configure explicit `global_roles` or seed the `media.manage`/granular `media.*-any` permissions. Include `media.manage-staging` only for actors allowed to adopt another actor's staged media. Role names default to an empty list and therefore do not grant new access during upgrade.
10. Application services may adopt `Nvl\Media\Facades\Media` or inject `MediaLibraryContract`. No global class alias is registered, and existing model-trait/action APIs remain valid.

Storage identities are now cryptographically random and use only the validated canonical extension. Upload and replacement actions detect MIME and SHA-256 from bytes, enforce dangerous multi-extension rejection, scan before storage, and verify stored size/checksum. Existing display filenames remain presentation metadata and no longer influence executable object keys.

The optional Spatie-compatible authorization bridge is additive and has no Composer dependency on `spatie/laravel-permission`. Configured global roles bypass uploader ownership consistently in policies, listing scope, and private delivery. They do not bypass shared-association integrity or quarantine. Disable `nvl-media.authorization.spatie_permission.enabled` when the application authorizer must be the sole source of cross-owner access.

The public action contracts are now honored consistently by model-trait, facade, lifecycle-service, and package-controller calls. Applications that intentionally replaced `UploadMediaContract`, `AttachMediaContract`, `DetachMediaContract`, `DeleteMediaContract`, or `ReusePublicMediaContract` no longer need to replace concrete package controllers or trait internals as well.

The owner-slot idempotency foundation adds the
`px_media_owner_slot_operations` table. Run migrations before deploying
owner-slot workflows. Custom deployments may isolate only this ledger with
`nvl-media.owner_slots.idempotency.connection` and
`nvl-media.owner_slots.idempotency.table`; the table must not collide with another
Media table. The ledger contains canonical request hashes and stable failure
codes, never request payloads or exception messages. Processing attempts use a
renewable 30-minute lease by default; recovery rotates the operation UUID and
invalidates stale claims. A separate ledger connection is not transactionally
atomic with owner/Media mutations, so custom workflows must reconcile or safely
retry a crash between mutation and ledger completion.

Replace application concerns that manually query `mediaAssociations`, copy all
custom properties, detach old files, or delete storage objects with
`GetOwnerMediaSlotAction`, `ReplaceOwnerMediaSlotAction`,
`ClearOwnerMediaSlotAction`, and `CopyOwnerMediaSlotAction`. Keep only the
model's registered `MediaSlot` policy and domain convenience accessors. Existing
staged files must satisfy the registered MIME, size, visibility, ownership, and
sharing rules; slots with a custom file acceptor receive new uploads/copies, not
already-persisted replacement rows. Configure
`nvl-media.owner_slots.copy.metadata_keys` as an explicit list of safe scalar
presentation/provenance fields instead of copying arbitrary metadata.

After migration, run the owner-slot workflow tests, then schedule:

```bash
php artisan nvl:media:owner-slots:prune --days=7 --chunk=500
```

Production rejects a `--days` value shorter than configured retention unless
`--force` is explicit. `nvl:media:doctor --production --strict` also checks the
ledger, retention bounds, atomic mutation lock store, and observed owner-slot
model registrations. Idempotent workflows may participate in a host outer
transaction; events, file effects, and split-ledger completion follow the real
root outcome.

Replacement now re-applies the policies of every persisted association slot. Copy/move uses copy–verify–database-swap–delete, deletion keeps a soft-deleted diagnostic tombstone, and external cleanup/events/variation dispatch follow the real root transaction outcome. Applications that assumed immediate file deletion inside an outer transaction must wait for commit.

`addMedia($explicitLocalPath)` now implements its documented ownership contract: it deletes that explicit caller source only after the real successful commit. Use `copyMedia()` or `preservingOriginal()` when the path must remain. Request uploads and package-created temporary files are unaffected.

`withVariations()` now normalizes and persists named upload definitions. On shared deduplication, conflicting definitions for an existing label throw instead of silently changing the asset. `withoutVariations()` suppresses all automatic dispatch for that upload.

The clean 1.0 API has no pre-release compatibility controller. Multipart signing/completion/abort requests use only the server-issued upload ID, scan finalization requires `MediaScanResultData`, dynamic asset transformations are unsupported, and tag writes go through `MutateMediaTagsAction` or `BulkTagMediaAction`.

Review the dedicated [PHP API](docs/php-api.md), [HTTP API](docs/http-api.md), [configuration reference](docs/configuration.md), and [extension/event reference](docs/extending.md) during adoption. These references distinguish trusted PHP mutation calls from authorized HTTP entry points and document every supported 2.x public surface. Documentation coverage tests fail when a public facade, trait, fluent-adder, slot, conversion, model-helper, management-route, contract, event, or top-level configuration surface is added without corresponding reference documentation.

## Shared owner registry compatibility

Declare owner classes in `nvl-core.owners`, for example `'owners' => [Article::class]`, and reference the same model class from each package capability. Laravel's `getMorphClass()` is the stored owner identity: it returns the host-authored morph alias or the FQCN when no map exists. Core declarations and package allowlists do not add or enforce a host morph map and do not grant authorization.

Legacy alias references remain read compatibility during major 5 and remain deprecated in major 6. A legacy configured alias must agree with the model's current `getMorphClass()`; mismatches are diagnostics and require a host decision. Doctor can inspect declared package owner columns for stored-versus-current identities without rewriting them. If the host introduces or changes its morph map, review and convert only the affected stored columns and reconcile host relationships before cutover. No automatic owner-data conversion or `nvl:owners:upgrade` is provided. Rebuild configuration caches and restart workers after the coordinated change.

## Queue default inheritance

Remove a published queue connection only when you intend to inherit `nvl-core.queue.connection` or the effective Laravel `queue.default`. Retain an explicit `sync` to keep synchronous behavior. `NVL_MEDIA_QUEUE_CONNECTION` remains supported; `QUEUE_CONNECTION` is read by Laravel configuration, while Media resolves its effective runtime configuration. Rebuild configuration caches and restart workers after changing these settings. This change does not migrate data.


## Infrastructure option inheritance

Fresh Media queue names now inherit Core or the selected Laravel connection's queue. Keep an explicit `NVL_MEDIA_QUEUE=media` or `nvl-media.queue.name = 'media'` when existing workers use that dedicated name. Move operation lock stores to `locks.mutation.store`, `locks.deduplication.store`, and `locks.multipart.store`, or share `locks.store`; keep existing operation duration/wait values. Historical store keys remain supported for one major cycle and appear in Core diagnostics. Rebuild configuration caches and restart workers.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=media --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=media --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.

## Major 5: namespaced model capability

New integrations implement `HasNvlMedia` and use `InteractsWithNvlMedia`. Move slot/conversion definitions into `registerNvlMedia(MediaRegistration $media, ?Media $asset = null)`, using `$media->slot()` and `$media->conversion()`. Move uploads and reads to `$owner->nvlMedia()->add()`, `fromString()`, `all()`, `first()` and `firstUrl()`. Eager-load `nvlMediaAssociations`, whose NVL assets load with it. The facade, injected library contract and owner-slot Actions accept this capability.

The legacy `HasMedia` interface and `InteractsWithMedia` trait remain forwarding adapters throughout major 5 and remain deprecated in major 6. Existing NVL-only models using that trait retain their public calls and old registration overrides. Handwritten implementations of the old contract must also provide the new capability methods or adopt the forwarding trait. A host composing Spatie Media uses the new NVL contract/trait, preserving Spatie's `media()`, `addMedia()` and `registerMediaConversions()` unchanged. Do not compose the deprecated NVL trait on that dual-library model.

This API change performs no database, file or morph-identity conversion. NVL and Spatie collection operations use their own models, associations, disks and conversion definitions. Soft deletion preserves both sets; force deletion invokes each library's own cleanup. The reproducible standalone proof is `tools/run-media-spatie-consumer.sh`; Spatie is required only by its test fixture, never by the production Media manifest.

## Major 5 cache and lock identities

Mutation, deduplication, and multipart locks use `nvl:media:mutation:`, `nvl:media:deduplication:`, and `nvl:media:multipart:`. File existence entries use `nvl:media:file-existence:` instead of `media_exists:`. Invalidation touches only the owned entry; generic host keys are never read, copied, or removed.

Drain old mutation workers and maintenance processes, then wait for their outstanding lock leases to end before starting the new major across all nodes. Running old and new lock prefixes concurrently would create independent serialization domains. Restart workers after cutover; preserve host-selected stores and keys, and do not flush a shared cache to remove old NVL entries.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

The host-owned `MediaCatalogImport` port retains its authorized snapshot and staged-object tuples, including storage identity required for the transfer. `MediaCatalogSnapshot` and `StagedCatalogMedia` are integration payloads; do not expose their storage paths as display fields or treat them as permission to access arbitrary backing objects.

`MediaOwner::query()`, `associations()`, `ownerDeleted()`, `owns()`, and `invalidate()` plus capability relation methods are internal storage or lifecycle seams. Migrate direct traversal, eager/lazy loading, and aggregate queries to the package authorized read Actions or explicit C1 host scopes/adapters. Native host queries and opted-in Translatable behavior remain supported.

`MediaUsage::fromModel` is an internal association projector. Use `MediaLibraryContract::usages($id)` to obtain its usage DTO collection; MediaAssociation itself remains an identity-only handle.
