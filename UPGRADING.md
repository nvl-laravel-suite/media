# Upgrading NVL Media

## Upgrading to 1.0

Version 1.0 uses UUID media records, string-compatible morph identifiers, explicit lifecycle and visibility, localized metadata rows, and separate management and delivery routes.

1. Set `media.migrations.enabled=false` for an existing schema.
2. Run `php artisan nvl:media:doctor --strict --format=json`.
3. Stage the legacy Spatie-style table under a non-canonical name, run `php artisan nvl:media:adopt-spatie --source=... --format=json`, resolve every mapping/path error, then repeat with `--apply`. Pass `--translations` and `--variations` for staged companion tables.
4. Register owners with `HasMedia` and `InteractsWithMedia`.
5. Bind `MediaAuthorization`, a production `MediaContentScanner`, and a `MultipartUploadGateway` when needed.
6. Enable asset delivery independently of management APIs.
7. Run read-only `php artisan nvl:media:reconcile --production` before and after cutover.

If existing `folder` values are already complete disk-relative paths, set `MEDIA_ROOT_FOLDER=` during in-place adoption. Leaving the default `media` prefix would point every imported row at a different object. Keep the empty root only for a dedicated disk, or move objects through `nvl:media:migrate-disk` into the prefixed layout. `nvl:media:doctor` samples persisted rows and flags this mismatch.

Treat any strict `storage.persisted_paths` failure as a data incident. Diagnose
and restore missing objects before considering association or row removal; do
not schedule `nvl:media:reconcile --cleanup-orphans` as an adoption shortcut.

### Image and queue configuration

The v1 configuration uses `media.queue.enabled`, `media.queue.connection`, `media.queue.name`, and `media.queue.jobs.*`. Remove the pre-v1 flat `queue_conversions`, `queue_connection`, and `queue_name` keys.

Image presets now accept `ImageFit`, `ImageFormat`, and `ImageCompression` enum values. `max_size` with `ImageFit::Max` preserves aspect ratio and never upscales. The built-in `optimized` output is bounded to 1200 pixels.

S3 omits per-object ACLs by default for Bucket Owner Enforced compatibility. Set `MEDIA_S3_USE_ACL_VISIBILITY=true` only for an ACL-enabled bucket. Changing `media.variation_naming.pattern` changes derived paths and requires controlled regeneration.

Preserve identifiers and verify row counts, checksums, associations, physical objects, signatures, and rollback before removing old code.

## Upgrading to the production-hardened 1.x release

The clean v1 schema includes immutable variation paths, persisted upload-specific definitions/listing indexes, and server-owned multipart sessions directly in its create migrations.

1. Require `ext-curl`, deploy the new code, and run `php artisan migrate` before enabling ingestion.
2. Merge the new `authorization`, `sources.remote`, `integrity`, `mutation_lock`, `multipart`, `query`, `reconciliation`, and `deployment` configuration groups.
3. Change custom `media.file_types` entries to `extension => string|list<string>` where a canonical extension has legitimate MIME aliases. Existing string entries remain valid.
4. Configure every production disk with `throw=true`, use Redis-backed mutation/deduplication locks and durable queues, and keep queue `retry_after` or SQS visibility timeout above every Media job timeout.
5. Bind a real `MediaContentScanner` whenever untrusted uploads are accepted. `nvl:media:doctor --production` fails closed on the no-op scanner.
6. Keep multipart disabled unless direct uploads are required. When enabled, configure a recoverable gateway, Redis-backed locks, scanner attestation, expired-session pruning, and the production integration gate.
7. Run `php artisan nvl:media:doctor --production --strict --format=json`, followed by read-only `php artisan nvl:media:reconcile --production --orphans`.
8. Remote URL ingestion is now opt-in. Set `MEDIA_REMOTE_SOURCES_ENABLED=true` only where it is used, then canary it separately after the ordinary upload path.
9. If the application uses Spatie Permission, configure explicit `global_roles` or seed the `media.manage`/granular `media.*-any` permissions. Role names default to an empty list and therefore do not grant new access during upgrade.
10. Application services may adopt `Nvl\Media\Facades\Media` or inject `MediaLibraryContract`. No global class alias is registered, and existing model-trait/action APIs remain valid.

Storage identities are now cryptographically random and use only the validated canonical extension. Upload and replacement actions detect MIME and SHA-256 from bytes, enforce dangerous multi-extension rejection, scan before storage, and verify stored size/checksum. Existing display filenames remain presentation metadata and no longer influence executable object keys.

The optional Spatie-compatible authorization bridge is additive and has no Composer dependency on `spatie/laravel-permission`. Configured global roles bypass uploader ownership consistently in policies, listing scope, and private delivery. They do not bypass shared-association integrity or quarantine. Disable `media.authorization.spatie_permission.enabled` when the application authorizer must be the sole source of cross-owner access.

The public action contracts are now honored consistently by model-trait, facade, lifecycle-service, and package-controller calls. Applications that intentionally replaced `UploadMediaContract`, `AttachMediaContract`, `DetachMediaContract`, `DeleteMediaContract`, or `ReusePublicMediaContract` no longer need to replace concrete package controllers or trait internals as well.

Replacement now re-applies the policies of every persisted association slot. Copy/move uses copy–verify–database-swap–delete, deletion keeps a soft-deleted diagnostic tombstone, and external cleanup/events/variation dispatch follow the real root transaction outcome. Applications that assumed immediate file deletion inside an outer transaction must wait for commit.

`addMedia($explicitLocalPath)` now implements its documented ownership contract: it deletes that explicit caller source only after the real successful commit. Use `copyMedia()` or `preservingOriginal()` when the path must remain. Request uploads and package-created temporary files are unaffected.

`withVariations()` now normalizes and persists named upload definitions. On shared deduplication, conflicting definitions for an existing label throw instead of silently changing the asset. `withoutVariations()` suppresses all automatic dispatch for that upload.

The clean 1.0 API has no pre-release compatibility controller. Multipart signing/completion/abort requests use only the server-issued upload ID, scan finalization requires `MediaScanResultData`, dynamic asset transformations are unsupported, and tag writes go through `MutateMediaTagsAction` or `BulkTagMediaAction`.

Review the dedicated [PHP API](docs/php-api.md), [HTTP API](docs/http-api.md), [configuration reference](docs/configuration.md), and [extension/event reference](docs/extending.md) during adoption. These references distinguish trusted PHP mutation calls from authorized HTTP entry points and document every supported 1.x public surface. Documentation coverage tests fail when a public facade, trait, fluent-adder, slot, conversion, model-helper, management-route, contract, event, or top-level configuration surface is added without corresponding reference documentation.
