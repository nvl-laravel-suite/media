# Changelog


All notable changes to `nvl/media` are documented here.

## [Unreleased]

## [7.0.4] - 2026-11-11

### Fixed

- Return a model's media associations in a stable order when several share the same `order` value (the default is 0). The `nvlMediaAssociations()` relation and the `ordered()` scope sorted by `order` alone, so PostgreSQL and MySQL could return two files of one slot in either order; they now break ties by identifier, as the paginated readers already did.
- Keep builder-level `forceDelete()`, `updateOrInsert()` and `updateFrom()` on Media inside the ownership boundary by adopting Core's owned write trait on the Media builder. Require Core 7.0.6.
- `MediaAttachments::deleteAll()` no longer throws `MediaInUseException` when one public asset fills two collections of the owner being cleaned up; each placement is removed in turn. Removing such an asset through `remove()` or `removeFromModel()` without naming a collection is still refused.
- Accept WAV, RAR and ADTS AAC uploads with the default allowlist by adding the MIME names libmagic reports (`audio/x-wav`, `application/x-rar`, `audio/x-hx-aac-adts`).
- Association locales accept the family's locale codes up to 35 characters (`zh-Hans`, `sr-Latn-RS`): a new migration widens `locale` from 5, `attach()` validates and canonicalises the code and rejects malformed values, and the library locale filter and Spatie adoption accept the same length.
- Stop queueing image variations for SVG and ICO sources when the image driver is GD, which cannot decode them; Imagick and Vips are unchanged.
- Deleting a file on a local disk checks its folder with one shallow, lazy listing instead of listing the folder recursively, and leaves a folder that still holds a subdirectory in place.
- `RegenerateMediaVariationsJob` handles one chunk per run and continues from its last id in a new job (inside the same batch when batched), so a large catalogue no longer has to fit one timeout and a cancelled batch stops.
- The media mutation lock is held until the Media connection's transaction ends when Media uses a connection other than the default.
- Resolving an association owner type no longer constructs every registered ownership resource model.
- `nvl:media:doctor` reports `images.variations.authorization` when image variations are enabled while the default `MediaAuthorization`, which denies the background actor, is still bound.
- Configuration errors and command output name the real `nvl-media.*` keys; removed a config-merge step that read a non-existent key and never ran.
- Treat a malformed media identifier as absent in every lookup. On PostgreSQL a non-UUID identifier raised a database error where a not-found result was expected.
- Aborting or pruning a multipart session that reached completion deletes the object the provider may already have assembled; the session stays retryable when that delete fails.
- S3 multipart completion no longer downloads the whole object inside the request to hash it. S3 validates each part against its signed SHA-256; the asset stays `pending_scan` and `MediaScan::finalize()` hashes the stored bytes before it becomes available. `MultipartUploadGateway::complete()` and `RecoverableMultipartUploadGateway::inspect()` may return `checksum: null` when the provider attests no full-object SHA-256.
- `MediaSlot::convertTo()`, `withQuality()` and `maxSize()` now convert the original inside `MediaUploader::upload()`, and `withTags()` adds its tags to every upload into the slot. Slot rules apply to the submitted file; `copySlot()` keeps the source bytes.
- `nvl:media:adopt-spatie` reads legacy tables from the Media connection in chunks of 250, checks storage once per original, inserts and reconciles per chunk inside one transaction, and reports over-long legacy values in the dry run.
- `nvl:media:reconcile` uses `nvl-media.reconciliation.orphan_age_minutes` when `--older-than` is not passed, and no longer copies its referenced-path set once per row.
- Remove configuration nothing read: `nvl-media.integrity.*` and `nvl-media.multipart.required_scan`, with their doctor checks. Integrity verification and the multipart scan requirement are unconditional.
- Hold the deduplication lock until the host transaction ends, so two identical uploads inside open transactions no longer both insert.
- Library search treats `%`, `_` and `\` as literal text and ignores letter case on SQLite, MySQL, MariaDB and PostgreSQL alike.
- `Media::getUrl('thumb')` and `MediaImageVariation::getUrl()` use the cached existence lookup instead of asking the disk on every call.
- Refuse to decode an image whose header declares more than `nvl-media.image_constraints.max_pixels` (64,000,000) pixels.
- Integer settings supplied as numeric strings by environment variables are honoured instead of falling back to the default.
- A non-manager library no longer lists another uploader's public asset that is pending scan, quarantined or failed.
- A failed regeneration keeps a variation that is still valid for the current source revision deliverable; it records the failure and attempt only.
- `MediaAttachments::getSlot()` returns null and `clearSlot()` / `replaceSlot()` succeed when the slot's Media record was deleted or quarantined after attachment.
- The SVG scanner rejects external references on every element except `<a>` (for example `feImage`, `pattern`, gradients, `textPath`), CSS `image-set()`, and elements in the XHTML namespace.
- `nvl:media:migrate-disk` loads, locks and updates its selection in chunks of 1,000 identifiers, and a folder migration rewrites only the exact prefix: `_` and `%` are literal and letter case must match.
- `nvl:media:doctor` inspects Media tables and persisted paths on the Media connection.
- `ConversionDefinition::crop()` honours its position, `watermark()` honours its opacity, and `validate()` rejects flip, orientation and position values the image pipeline would ignore.
- Remove unused `MediaHashGenerator::generate()`, `MediaFileOperator::deleteDirectory()` and the `MediaTables::MEDIA*` aliases.

<!-- NVL GENERATED CHANGES START -->
### Changed

- scope builder writes, stabilise ordering and harden uploads and adoption
<!-- NVL GENERATED CHANGES END -->

## [7.0.3] - 2026-11-10

### Changed

- Remove the obsolete Spatie Laravel Data development dependency. Media test adapters now use native validation and explicit projections without TypeScript transformer attributes.

<!-- NVL GENERATED CHANGES START -->
### Changed

- retire Spatie Data test dependency
<!-- NVL GENERATED CHANGES END -->

## [7.0.2] - 2026-11-09

### Changed

- Refresh the inherited Ownership deletion API reference for ordinary models and native pivots.

<!-- NVL GENERATED CHANGES START -->
### Changed

- refresh inherited ownership deletion contracts
<!-- NVL GENERATED CHANGES END -->

## [7.0.1] - 2026-11-04

<!-- NVL GENERATED CHANGES START -->
### Changed

- support PSR-7 3 in remote downloads
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

### Changed

- Target PHP 8.4, Laravel 13 and major 7 NVL peers; replace transport DTOs, package Actions/controllers/routes/facades and fluent workflows with native actor-authorized services and consumer-owned adapters.
- Configure delivery per disk and visibility, with application URLs by default, explicit authorized bearer-link issuance and bounded lifetimes, policy admission for writes and delivery, and verified cross-disk visibility changes.
- Keep S3 and model-declared ownership optional, and align installation, operational guidance and packaged agent instructions with the native API.
- Exclude local vendor trees and Composer lock files from release archives.
- Exclude Image Optimizer versions with known security advisories from supported dependency resolutions.
- Validate original SVG bytes without entity expansion; reject DTDs, processing instructions, external/obfuscated CSS and nested SVG data URLs. Document attachment, nosniff and sandbox delivery requirements.
- Keep complete remote URLs and unexpected transport details out of public upload failure messages while preserving diagnostic causes and temporary-file cleanup.

## [6.1.0] - 2026-10-23

<!-- NVL GENERATED CHANGES START -->
### Changed

- expose safe host APIs and harden package boundaries
<!-- NVL GENERATED CHANGES END -->

## [6.0.1] - 2026-10-21

### Changed

- Share configured Media registration and native adoption paging. Isolate retained legacy field guards while preserving the custom builder, binary validation, private paths and source transactions.
- Require optional Ownership >=6.0.1 when installed so new shared backfill callers cannot resolve an older runtime. Require Core >=6.0.1 for the shared model, queue or compatibility implementation.

## [6.0.0] - 2026-10-20

### Changed

- Support configured Media subclasses with native columns or exclusive pivots. Derive associations, variations and translations from the canonical asset; fence multipart and slot receipts, private delivery, storage copies and worker restoration while preserving asset UUIDs and original tenant IDs.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.

## [5.1.0] - 2026-10-09

### Added

- Add disk-specific `root_folders` with explicit empty roots, and optional public/private upload disk defaults that preserve explicit selections and the existing single-disk fallback.

### Changed

- Resolve uploads, variations, replacements, multipart, owner-slot copies, catalog imports and adoption against the selected disk root.
- Relocate and migrate originals and variations into destination roots with verified copies and rollback; validate storage configuration through Doctor and refuse whole-disk orphan deletion.
- Teach per-disk configuration, empty logical folders, migration and reconciliation safeguards in the shipped agent skill and operational references.

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.


## [5.0.0] - 2026-10-08

### Added

- Add focused consumer contracts for all selected Media workflows, preserving the six established Action seams.
- Add `MediaUploadBuilder`, `FakeMediaLibrary`, and `FakeMediaUploadBuilder` with explicit native-return FIFO scripts, immutable call records, and complete fluent request snapshots.

- Add bounded authorized owner-slot DTO reads with explicit SQL View policy, canonical owner admission, exact identity correlation, bounded locale projection, and no variation queries.
- Add the independent `hasNvlMedia()` host existence scope with canonical connection/FROM guards and complete SQL authorization.

### Changed

- Widen only the eight library-contract/facade source returns to `MediaUploadBuilder`; retain real `MediaAdder` return covariance and upload behavior.
- Preserve early host bindings for selected workflow and extension defaults while retaining scoped library/catalog lifetimes.

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Preserve early host `MediaAuthorization` bindings with `bindIf`; private batch URLs remain null and use separate authorized Download delivery.

- Isolate owned cache and lock keys under `nvl:media:`; preserve generic host entries and explicit store choices.

- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
- Introduce HasNvlMedia and InteractsWithNvlMedia with an owner API and independent eager-loadable association relation.
- Retain legacy forwarding for one major and verify coexistence with the real Spatie Media library.
- Gate generic permission bridging and namespace asset routes, lock keys and presentation.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.2.1] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

## [2.2.0] - 2026-09-25

### Changed

- Prepare `nvl/media` for independent Composer and Git publication; require `nvl/core` for shared Support and Data services.
- Correct the published agent skill's documentation path for standalone `nvl/media` installations.

## [2.1.1] - 2026-09-23

### Changed

- Replace the management JSON Resource and resource-named factory with
  `MediaManagementData` and `MediaManagementDataFactory`, preserving privileged
  fields, conditional relationships, and nullable URL behavior.

## [2.0.1] - 2026-09-22

- Documented opt-in tenant ownership, independent catalog copies, immutable
  adoption mappings, backup-based recovery, and bounded tenant cleanup.

### Added

- Published the existing `ImportPlatformMediaData` contract in the generated
  TypeScript declarations.

### Fixed

- Limited Doctor's persisted-path sampling to live Media records, so
  intentionally removed objects for soft-deleted diagnostic tombstones no
  longer fail readiness checks. Tenant-owned immutable storage paths are
  included only when the opt-in tenancy schema provides that column, preserving
  standalone compatibility across supported database engines.
- Recheck attachment availability and public-reuse visibility on the current
  database row inside the media mutation lock and association transaction.
  Stale model instances can no longer bypass these guards or reject an asset
  that has since become eligible. Existing calls remain compatible; the
  concrete attachment action adds an optional public-visibility requirement.

## [2.0.0] - 2026-08-29

### Changed

- Established Media Actions, `MediaLibrary`, `MediaQueryService`, and owner
  traits as the 2.0 consumer boundary; direct consumer Media-model queries and
  relation aggregates now fail Suite audit.

## [1.0.7] - 2026-08-22

### Added

- Added an exact active-association query boundary for owner packages that need
  association-scoped projections or delivery without querying Media internals.
- Added an explicit local command and disposable-service contract for the
  PostgreSQL, Redis, and S3-compatible multipart recovery proof.

### Changed

- Aligned production support and quality guidance with the PHP 8.4+ runtime
  baseline and local production-stack verification workflow.

## [1.0.5] - 2026-08-12

### Changed

- Added a missing-binary incident recovery runbook and verified explicit,
  shared-lock multipart scheduler ownership guidance.

## [1.0.3] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version after the v1.0.2 Media
  hardening work.

## [1.0.2] - 2026-08-12

- Replaced the global media-hash uniqueness constraint with an indexed reusable hash, added generated-binary ingestion, existence-safe nullable URLs, one-record cross-disk relocation, and a single PUT/PATCH management route declaration.
- Added dry-run-first `nvl:media:adopt-spatie` conversion with deterministic identifiers, association/translation/variation mapping, backing-path and count reconciliation, plus Doctor checks and empty-root guidance for in-place storage adoption.
- Registered migration publication through Laravel's timestamp-aware API and made Doctor warn when automatic vendor loading overlaps a published host copy.
- Fixed the Spatie-compatible granular permission fallback so Eloquent's
  dynamic method proxy cannot prevent `hasPermissionTo` from being evaluated.
- Allowed association detachment from soft-deleted Media tombstones so owning
  domains can complete privacy and lifecycle cleanup without deleting Media.
- Added complete PHP, HTTP, configuration, and extension/event references, including every model-trait/facade/adder/slot/conversion method, management endpoint, DTO boundary, status/error shape, configuration group/environment variable, binding contract, lifecycle event, and operational log category; added automated documentation-drift coverage.
- Added an injectable `MediaLibraryContract` and `Nvl\Media\Facades\Media` for fluent source ingestion, scoped reads, associations, lifecycle mutations, scan finalization, authorization checks, and persisted multipart operations without duplicating action logic.
- Made upload, attach, detach, delete, and public-reuse contract overrides apply consistently across the model trait, fluent adder, lifecycle services, package controllers, and facade.
- Added `MediaAdder::usingSlot()` so model and facade upload builders share one fluent slot-selection path.
- Added the documented `media-migrations` publish tag.
- Added one authoritative byte-detected ingestion pipeline for uploads, replacements, requests, local/disk/base64/remote sources, and scanner-attested multipart finalization.
- Added extension-to-MIME alias lists, dangerous multi-extension rejection, canonical opaque object names, synchronous scanner enforcement, and stored size/SHA-256 verification.
- Added root-transaction-aware storage effects, recoverable post-commit cleanup, Redis/cache mutation locks, sorted bulk locking, copy–verify–swap moves, soft-delete tombstones, and revision-safe immutable variation replacement.
- Added bounded temporary-file ownership, streamed disk/base64 sources, DNS-pinned cURL remote fetching, redirect revalidation, connected-IP verification, and configurable SSRF/time/byte boundaries.
- Implemented persisted upload-specific named variations, explicit deduplication conflict handling, deterministic replacement/regeneration, and full `withoutVariations()` suppression.
- Added persisted encrypted multipart sessions, first-party recoverable S3-compatible multipart support, checksummed fixed-size parts, idempotent completion/abort/recovery, `MediaScanResultData`, and expired-session pruning. Multipart is opt-in and production-gated by recoverability, central locks, scanner attestation, and the PostgreSQL/Redis/S3 integration suite.
- Added orphan inventory and protected explicit cleanup to `nvl:media:reconcile`, plus structured storage, integrity, scanner, remote-source, stale-work, and multipart recovery diagnostics.
- Added production configuration groups, `MediaSearchDriver`, a page-size cap, listing indexes, focused controllers, and expanded `nvl:media:doctor --production` checks.
- Added an optional Spatie Permission-compatible global role and granular permission bridge for consistent cross-owner listing, delivery, mutation, and deletion; role names remain opt-in and failures fall back to ownership.
- Made remote URL ingestion opt-in so ordinary uploads do not incur DNS/cURL policy, while retaining pinned, connected-IP-attested fetching whenever the capability is enabled.
- Removed boolean scan finalization, client-authoritative multipart mutation fields, dynamic transformation configuration, `MediaTransformationConfig`, the compatibility API controller, and model tag-mutation wrappers from the clean v1 surface.
- Consolidated lifecycle, uploader, deduplication, association, variation, and localized-metadata schema into the clean-install create migrations.
- Standardized the localized-metadata table as `px_media_i18n`.
- Added first-class S3 Flysystem support with private-at-rest ACL-safe defaults, explicit visibility handling, streamed cross-disk copies, and no folder-marker writes.
- Added enum-driven WebP/AVIF format profiles, configurable compression/quality, proportional max-size resizing, three overrideable presets, and safe configurable variation filenames.
- Added complete queued-conversion payload parity, per-job retry/timeout/backoff/uniqueness configuration, source-revision protection, and terminal failure reporting.
- Extended the doctor with S3, image driver/encoder, and queue timeout diagnostics.
- Added dedicated S3, image/queue, and command operations guides.

## [1.0.0] - 2026-08-08

- Added private one-to-one media and reusable public assets with policy-bounded deduplication.
- Added proxied and multipart uploads, checksums, scanning, quarantine, lifecycle states, and idempotent finalization.
- Added authorized GET/HEAD/range delivery, asynchronous variations, localized metadata, and reconciliation.
- Removed consumer-specific contracts, legacy APIs, concrete user assumptions, and internal-path exposure.
