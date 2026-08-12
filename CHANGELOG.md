# Changelog

All notable changes to `nvl/media` are documented here.

## [Unreleased]

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
