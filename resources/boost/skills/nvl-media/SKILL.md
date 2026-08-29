---
name: nvl-media
description: Implement, integrate, test, or review nvl/media in Laravel 13. Use for private single-file media, reusable public assets, uploads, multipart object storage, scanning, checksum deduplication, associations, delivery, ranges, localized metadata, image variations, reconciliation, or media authorization.
---

# NVL Media

Treat binary lifecycle, database lifecycle, associations, delivery, and localized metadata as one security boundary. Never expose internal storage paths through public DTOs.

The supported 2.x production profile is PHP 8.3/8.4, Laravel 13, PostgreSQL, S3-compatible private storage with exception-enabled writes, Redis cache/locks/queues, and a real scanner. Multipart is opt-in and requires the recoverable gateway, central session locks, scanner attestation, pruning, and the PostgreSQL/Redis/S3 integration gate. SQLite/local/array/sync are development-compatible but do not prove production concurrency guarantees.

## Read the canonical references

- Resolve canonical references from
  `vendor/nvl/laravel-suite/packages/nvl/media/docs/` in a consumer application
  or `packages/nvl/media/docs/` in the suite repository.
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

- Define slots on a `HasMedia` owner and use `InteractsWithMedia`.
- For application services, inject `MediaLibraryContract` or explicitly import `Nvl\Media\Facades\Media`; never assume a global class alias. The facade delegates to the same Actions and contracts as the trait.
- Treat facade mutations as trusted application-service calls. Authorize user-driven operations with policies or `Media::allows()` before invoking them.
- In jobs and console commands, call `MediaAdder::uploadedBy($actor)` before the terminal upload method when private ownership or uploader-scoped deduplication matters.
- Use a private one-to-one slot when an owner has one replaceable exclusive file.
- Use reusable public media for assets that may have many associations and global checksum deduplication.
- Keep private deduplication inside the configured uploader, tenant, or owner boundary.
- Use the optional Spatie-compatible bridge for cross-owner administrators. Keep global role names explicit; prefer `media.manage` or granular `media.*-any` permissions. Privilege bypasses uploader ownership, not association integrity or quarantine.
- Use `AttachMediaAction`, `DetachMediaAction`, or `ReusePublicMediaAction`; never write association rows directly.

## Upload and finalize

- Use `UploadMediaAction` for proxied uploads.
- Treat detected bytes, never client MIME/extension/headers/object metadata, as authoritative. Keep `media.file_types` as `extension => string|list<string>`.
- Keep all materialized uploads on `MediaIngestionPipeline`: dangerous multi-extension rejection, canonical type, size/slot policy, scanner, SVG policy, SHA-256, random canonical storage name, and stored-object verification.
- Keep remote URL ingestion disabled unless the application uses it. When enabled, retain public-address validation, cURL pinning, redirect revalidation, connected-IP attestation, and byte/time limits; test transports may opt out of attestation outside production.
- For direct object storage, use `InitiateMultipartUploadAction`, `SignMultipartPartAction`, `CompleteMultipartUploadAction`, and `AbortMultipartUploadAction`; every mutation accepts the opaque session ID and reloads persisted actor-owned state.
- Keep multipart disabled unless direct uploads are needed. Enabled production sessions require the recoverable S3-compatible gateway, central locks, per-part length/SHA-256, `MediaScanResultData` attestation, pruning, and provider integration tests.
- Bind a real `MediaContentScanner` for production untrusted ingestion.
- Do not make multipart media available before matching size/checksum/type scanner attestation.
- Keep completion idempotent and protect deduplication and per-media/session mutation with central atomic locks.
- `addMedia($explicitLocalPath)` deletes that owned source only after root commit; use `copyMedia()` or `preservingOriginal()` to retain it.

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

- Run `nvl:media:doctor --production --strict --format=json`.
- Use read-only production inventory with `nvl:media:reconcile --production --orphans`.
- Never clean orphans implicitly. Require `--cleanup-orphans`, an age threshold, and `--force` in production; retain age-unknown objects.
- Schedule `nvl:media:multipart:prune` wherever multipart is enabled with persisted sessions and the recoverable gateway.
- Use `nvl:media:regenerate` and `nvl:media:migrate-disk` for controlled maintenance.
- Test public reuse, private isolation, root commit/rollback, concurrent replacement/variation/finalization, scanner failure, SVG safety, SSRF/DNS pinning, temporary-file release, signed expiry, Range/HEAD behavior, multipart recovery, missing storage, localized metadata, and orphan reconciliation.
