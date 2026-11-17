# Upgrading to major 7

Enabled SVG scanning now rejects DOCTYPE declarations, processing instructions, XML Base overrides, CSS escapes/comments or external CSS resources, and embedded SVG data URLs. Re-export affected design-tool files without these features. XML declarations, local paint references and supported raster data URLs remain accepted. Scanning validates the original bytes and does not sanitize documents for same-origin inline delivery; retain attachment/nosniff/sandbox controls from [HTTP integration](docs/http-api.md).

Major 7 targets PHP 8.4 and Laravel 13 and intentionally removes the previous DTO, Action, controller, route, facade and fluent-builder API. Consumers own their application adapters. Replace those calls with the complete [native services](docs/php-api.md); keep service authorization, validation, ownership and lifecycle guarantees.

The deprecated Tenancy bridge and its configuration have been retired. Use declared native ownership resources and explicit admitted contexts. Unowned installations remain supported. Existing tenant-named physical columns or historical facts are not an automatic schema-renaming instruction.

Upgrade required NVL peers to compatible major 7 releases. Local-only, unowned hosts need no S3 SDK/adapter or ownership runtime. Before selecting built-in S3 storage, install `league/flysystem-aws-s3-v3:^3.0` and `aws/aws-sdk-php:^3.322.9`; before declaring ownership, install `nvl/ownership:^7.0`. Optional packages outside those supported ranges are rejected by Composer.

Bind host authorization and batch SQL contracts explicitly. Background actors require policy grants. Register slots through `HasNvlMedia` and `MediaRegistration`. Return models through consumer-owned resources and keep private storage/scanner fields out of public responses.

Implement your own [HTTP adapters](docs/http-api.md), management middleware and signed asset endpoints. Update configured delivery route names to those consumer routes. Preserve byte identity, private access checks, scanning, idempotency, transaction callbacks and multipart recovery.

Review [storage configuration](docs/configuration.md), [extension contracts](docs/extending.md), [S3](docs/s3.md) and [operational commands](docs/commands.md). Existing files do not move when prefixes or visibility defaults change. Choose one migration owner and review populated tables before applying schema changes.

Delivery now uses `nvl-media.delivery.disks` with per-visibility defaults and allowed modes. Replace `assets.remote_public_delivery` and driver-dependent local URL behavior with explicit `direct` or `application` policies. A configured filesystem `url` no longer selects the delivery mode. Both defaults use application routes; register `assets.authenticated_route_name` for private authenticated delivery as well as the public and signed routes. Set visibility policies to `false` on disks which cannot safely admit them.

`getUrl()` and `buildUrl()` no longer implicitly issue private bearer links. They return an authenticated private application URL. `getTemporaryUrl()` and `buildPrivateUrl()` require an explicit actor and expiration; service consumers use `MediaUrlResolver::signedUrl()` or `temporaryUrl()`. The ambiguous resolver `privateUrl()` and global URL-lifetime settings are retired. Lifetimes are explicit and bounded by the disk's `maximum_ttl` in seconds. Signed application endpoints must reject a stale revision for every asset and treat a valid scoped signature as a bearer grant; use the authenticated endpoint when every recipient must log in.

Metadata `isPublic` updates may retain the current value but cannot change it. Use verified cross-disk `relocate()` with the target visibility. Multi-owner public assets cannot become private while shared. Preserve existing records and objects; these API changes require no reimport or automatic schema migration. Filesystem cleanup follows both host savepoint rollback and outer commit outcomes. New objects are removed after their originating savepoint or outer transaction rolls back; superseded objects are removed only after the outer commit. Keep native transaction management on the actual Media connection. Cleanup is best effort and logged, not a distributed storage transaction or durable outbox; reconcile failures and process crashes.

Replace old event aliases and model-bearing listener payloads with the canonical classes in the [event reference](docs/events.md). Drain old queued payloads and restart workers. Republish agent guidance with `nvl-media-skills` after reviewing any application-owned changes.

The media doctor now detects production itself. Leaving out `--production` no longer means development checks. `nvl:media:doctor` and the aggregate `nvl:doctor` apply production rules whenever the application environment is production; `--production` still forces them elsewhere.

Adoption runs through nvl:adopt with reviewed, checkpointed batches and an explicit activate step. Committed batches survive a failure. Keep writers stopped until verify and activate pass. Enable `nvl-core.adoption.migrations.enabled` and migrate, write the legacy source options as a JSON manifest, then run `php artisan nvl:adopt media plan|apply|verify|activate <manifest.json>`. Media stages assets, associations, variations and translations in place and copies no files; a missing backing file fails verify and blocks activation. `MediaSchemaAdopter` keeps its behaviour as a compatibility wrapper.

## Upgrading to the next 7.x release

Grant `MediaAbility::FinalizeScan` to the actor your scanner uses. `MediaScan::finalize()` now requires it instead of `mutate`, and the default policy never grants it, so an uploader cannot approve its own upload. A custom `MediaAuthorization` that allows every ability keeps working; review it so that ordinary uploaders do not hold this one.

Deduplication now asks your policy for `reuse` on the existing asset (and `mutate` when the upload adds variation definitions). When either is denied the upload stores a separate asset. Exclusive slots never deduplicate, and `deduplicateExisting: true` with an exclusive slot throws `InvalidArgumentException`.

Owner-slot workflows, `MediaAttachments::attach()`, `detach()`, `removeFromModel()` and `reuse()`, and `MediaLibrary::delete($actor, $media, owner: $owner)` pass the owner reloaded from storage to your policy, not the instance you supplied. Policies that read unsaved attributes from the owner must read persisted ones. `MediaLibrary::delete(owner: $owner)` therefore requires the owner record to still exist; a soft-deleted owner is accepted. `clearCollectionExcept()` throws `InvalidArgumentException` for an excluded item that is neither a `Media` model nor a UUID string.

Call multipart `initiate()`, `complete()` and `abort()` outside database transactions; inside one they now throw `MediaUploadException`. `nvl:media:migrate-disk` inside a caller's transaction defers source deletion until that transaction commits. `nvl:media:reconcile` rejects `--no-write` together with `--cleanup-orphans`.

`MediaImport::adopt()` reports staged rows whose ID matches but whose data differs, and refuses to apply until they are resolved. Earlier runs counted them as adopted; rerun the dry run before applying.

On PostgreSQL, rename GCT's colliding `px_media*` indexes, or keep the legacy tables on a separate source connection, before installing the native schema; see the README.

`nvl:adopt media` now refuses to resume when staged rows behind a stored checkpoint were deleted or changed. Restore them or start a new run. It needs the Core release that adds `AdoptionAdapter::assertCheckpoint()`.

`MediaAttachments::reuse()` now authorizes `MediaAbility::Reuse` on the asset in addition to `Associate`. Custom `MediaAuthorization` implementations must grant `Reuse` to actors that may attach existing public assets through `reuse()`.

`MediaLibrary::bulkDelete()` is now all-or-nothing: if any member is denied or cannot be deleted (for example a shared public asset), no member is deleted. Callers that relied on partial deletion must retry the remaining ids themselves.

`MediaAttachments::copySlot()` now also authorizes `MediaAbility::Download` on the source asset, because the copy hands its bytes to the destination slot. Grant `Download` to actors that copy slots.
