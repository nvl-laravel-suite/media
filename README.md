# NVL Media — API and usage

[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/media/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/media/security/advisories/new). See [Contributing](CONTRIBUTING.md).

See the [installation and publishing guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) for Composer setup, configuration, migration ownership, and agent skills.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/media:^5.0` |
| Module identifier | `nvl/media` |
| PHP namespace | `Nvl\Media` |
| Service provider | `Nvl\Media\Providers\MediaServiceProvider` |
| Configuration | `config/nvl-media.php` |

The suite's Laravel media module for secure uploads, private one-to-one files, reusable public assets, polymorphic ownership, image variations, localized metadata, centralized delivery, and safe lifecycle operations.

## Purpose and boundaries

Media owns the complete lifecycle of binary assets: ingestion, validation, content-scanner invocation, storage identity, attachment, visibility, delivery, variations, metadata translations, reuse, replacement, and deletion. It does not own application-specific editor UI, CDN provisioning, a particular virus-scanner vendor, digital-rights policy, or role provisioning; those integrate through slots, policies, configured disks, events, focused contracts, and the optional Spatie Permission bridge.

## Documentation

- [PHP API](docs/php-api.md): model trait, fluent adder, facade, injectable contract, slots, conversions, model helpers, DTOs, Actions, exceptions, and transaction semantics.
- [HTTP API](docs/http-api.md): authentication, authorization, every management and asset route, request validation, response schemas, status codes, errors, and multipart integration boundary.
- [Configuration](docs/configuration.md): every configuration group, default, environment variable, security boundary, and production recommendation.
- [Extension contracts and events](docs/extending.md): container bindings, scanners, authorization, search, DNS, multipart gateways, lifecycle events, operational logs, and testing.
- [Image variations and queues](docs/images-and-queues.md): presets, image drivers, workers, retries, and rollout.
- [S3 and object storage](docs/s3.md): disks, IAM, private-at-rest delivery, multipart, CDN, and deployment.
- [Command reference](docs/commands.md): doctor, reconciliation, pruning, regeneration, migration, flags, safety, and exit behavior.
- [Upgrading](UPGRADING.md), [security policy](SECURITY.md), [contributing](CONTRIBUTING.md), and [changelog](CHANGELOG.md).

The optional Spatie permission bridge defaults to `false` through `nvl-media.authorization.spatie_permission.enabled`. Explicit grants use `nvl.media.*` names, including `nvl.media.manage`; holding only `nvl.media.manage` never automatically authorizes access to another owner's private Media. Ownership and host authorization contracts remain authoritative when the bridge is disabled.

## Requirements and installation

- PHP 8.4+
- Laravel 13
- `ext-curl` for DNS-pinned remote ingestion
- `nvl/translatable` for localized media copy
- an image driver supported by `spatie/image`
- `ext-gd`, Imagick, or libvips with the encoders required by your chosen formats
- `league/flysystem-aws-s3-v3` is included for S3-compatible disks

```bash
composer require nvl/media:^5.0
php artisan migrate
```

Review the migration ownership choice below before migrating existing tables.
If the application needs a different connection, table layout, or storage
configuration, publish and edit config before migration. Agent skills are
optional:

```bash
php artisan vendor:publish --tag=nvl-media-config
php artisan vendor:publish --tag=nvl-media-skills
```

The package does not assume an application user model, UUID owner keys, storage provider, authorization package, or application middleware.

Choose exactly one migration owner:

1. **Automatic vendor loading (default):** leave `nvl-media.migrations.enabled=true`, do not publish `nvl-media-migrations`, and run `php artisan migrate`.
2. **Host-owned published migrations:** publish `nvl-media-migrations`, set `nvl-media.migrations.enabled=false` before migrating, and maintain the published files as application migrations.

   ```bash
   php artisan vendor:publish --tag=nvl-media-migrations
   ```

Never run both sources. Laravel retimestamps files published through the migration tag. `php artisan nvl:media:doctor` reports a warning when automatic loading remains enabled and `database/migrations` contains a timestamp-independent name matching a package migration; `--strict` promotes that warning to failure.

English and Bulgarian media copy ships with the package. Publish conventional Laravel overrides with `php artisan vendor:publish --tag=nvl-media-translations`. Localized asset metadata remains database-backed through `nvl/translatable`.

### Production support boundary

The supported 2.x production path is PHP 8.4+, Laravel 13, PostgreSQL, an S3-compatible private bucket, Redis-backed cache/locks/queues, and a real `MediaContentScanner`. Multipart remains opt-in; when enabled, production additionally requires a recoverable gateway, central multipart locks, scanner attestation, and the PostgreSQL/Redis/S3 integration gate. Run `php artisan nvl:media:doctor --production --strict` against the deployed configuration before accepting traffic.

SQLite, local disks, array locks, and synchronous queues remain supported for development and the fast test suite. They do not prove the multi-node production guarantees.

## Add media to a model

```php
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Traits\InteractsWithNvlMedia;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;

final class Article extends Model implements HasNvlMedia
{
    use InteractsWithNvlMedia;

    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        $media->slot('gallery')
            ->publicReusable()
            ->useDisk('public')
            ->path('articles/{model_id}/gallery')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxFileSize(8 * 1024 * 1024)
            ->onlyKeepLatest(20);

        $media->slot('datasheet')
            ->oneToOne()
            ->useDisk('local')
            ->acceptsMimeTypes(['application/pdf']);
    }
}
```

`publicReusable()` means public + shared digest deduplication. `oneToOne()` means private + exclusive + single-file replacement. Lower-level `isPublic()`, `shared()`, `exclusive()`, and `singleFile()` remain available for deliberate combinations.

## Upload through the model API

```php
$image = $article
    ->nvlMedia()->add($request->file('image'), 'gallery')
    ->usingFileName('front.webp')
    ->withTags(['catalog', 'front'])
    ->withCustomProperties(['source' => 'admin'])
    ->withAssociationMeta(['role' => 'primary'])
    ->toLocale('bg')
    ->slot();
```

The adder optimizes first, then the authoritative ingestion pipeline detects MIME and SHA-256 from the materialized bytes, enforces the extension/MIME allowlist, dangerous multi-extension rejection, global and slot limits, SVG policy, and `MediaContentScanner` before any write. Storage names are cryptographically random and retain only the validated canonical extension. Upload, replacement, local/disk imports, remote sources, base64, and request uploads converge on this boundary; direct callers of `UploadMediaAction` cannot bypass it.

For one-to-one slots, the previous association/file is removed only after the replacement was stored and attached successfully. A rejected or failed replacement leaves the current file intact.

Additional sources:

```php
$model->nvlMedia()->fromRequest('file')->slot('documents');
$model->nvlMedia()->fromUrl($url, 'image/jpeg')->slot('gallery');
$model->nvlMedia()->fromBase64($payload, 'image/png')->slot('gallery');
$model->nvlMedia()->fromString($text)->slot('documents');
$model->nvlMedia()->fromBinary($pdfBytes, 'report.pdf', 'application/pdf')->slot('documents');
$model->nvlMedia()->fromDisk($key, 'imports')->slot('documents');
```

Use `$owner->nvlMedia()->all()`, `first()`, `firstUrl()`, `attach()`, `detach()`, `sync()` and `slot()` for owner-scoped operations. Eager-load `nvlMediaAssociations`; its related NVL assets load with it. These names coexist with a host's `media()` relation and with Spatie Media's upload and conversion hooks.

`HasMedia` and `InteractsWithMedia` are deprecated forwarding adapters retained through major 5. NVL-only owners may keep their existing calls during that window. A model using both libraries implements `HasNvlMedia` and uses `InteractsWithNvlMedia` for NVL, alongside Spatie's unchanged contract and trait; no trait aliases are required. `tools/run-media-spatie-consumer.sh` proves this with the real Spatie dependency in isolated test tooling.

## Facade and injectable service API

The model trait is the shortest integration path. For application services, jobs, and controllers that should not be coupled to a specific owner instance API, import the package facade:

```php
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Facades\Media;

$asset = Media::add($article, $request->file('image'), 'gallery')
    ->withTags(['catalog'])
    ->withoutVariations()
    ->upload();

$page = Media::paginate(
    new MediaFilter(search: 'catalog', perPage: 50),
    actor: $request->user(),
);

abort_unless(
    Media::allows($request->user(), MediaAbility::Delete, $asset),
    403,
);

Media::delete($asset);
```

`Nvl\Media\Facades\Media` is a Laravel facade over `MediaLibraryContract`; it is not a truly static implementation. Consumers may inject `MediaLibraryContract`, fake the facade, replace the complete boundary, or replace focused upload/attach/detach/delete/reuse contracts. Those focused contracts are honored consistently by the trait, facade, lifecycle services, and package controllers.

The facade also exposes `copy`, `fromRequest`, `fromUrl`, `fromBase64`, `fromString`, `fromBinary`, `fromDisk`, `findOrFail`, `urlIfExists`, `usages`, `attach`, `reuse`, `detach`, `replace`, `rename`, `relocate`, `updateMetadata`, `generateVariation`, scan finalization, and persisted multipart operations. `fromBinary` accepts generated bytes plus a filename, detects MIME from the bytes, and enters the same validation/scanning pipeline as request uploads. Complex mutations keep their typed DTOs rather than accepting ambiguous option arrays.

Facade mutation calls are trusted application-service calls, like invoking an Action directly. They preserve validation, scanning, locks, transaction callbacks, and storage verification, but they do not authorize automatically. Ordinary uploads attribute the authenticated Eloquent actor when one is available. Jobs and console commands should call `uploadedBy($actor)` on the `MediaAdder`, or pass `uploadedBy` and `uploadedByType` to `UploadMediaAction`, when private ownership or uploader-scoped deduplication matters. Authorize at the HTTP/job boundary with Laravel policies or `Media::allows()` before invoking a mutation on behalf of a user.

The package intentionally does not register a global `Media` class alias because it would collide easily with application and Eloquent model names. Import the facade explicitly.

Remote URL ingestion is disabled by default. Enable it explicitly with `NVL_MEDIA_REMOTE_SOURCES_ENABLED=true` only in applications that accept remote sources. Ordinary request, local, disk, string, and base64 uploads do not enter the DNS/cURL path.

When enabled, remote and encoded sources are streamed into bounded package-owned temporary files and released in terminal `finally` paths. Remote requests allow only HTTP/HTTPS and configured ports, reject credentials and private/reserved A/AAAA results, pin cURL to the validated IP set, preserve TLS hostname verification, disable automatic redirects, revalidate every redirect, verify the connected IP, and enforce connect, total, redirect, and byte limits.

`nvl-media.sources.remote.verify_connected_ip` defaults to `true`. Disabling it is available for test fakes or a deliberately controlled custom transport; production doctor rejects that setting only when remote URL ingestion is enabled.

`nvlMedia()->add($explicitLocalPath)` owns that explicit source and removes it only after the real root transaction commits. `nvlMedia()->copy()` and `preservingOriginal()` retain it. Request uploads, package-created temporary files, and files not explicitly owned by the adder are never treated as caller-owned deletion targets; failures and rollbacks retain explicit local sources.

## Direct multipart object-storage uploads

Multipart is disabled by default. Disabled deployments bind `UnsupportedMultipartUploadGateway`; enabling it selects the first-party recoverable S3-compatible gateway unless the application configures another implementation. The server persists encrypted provider state and every technical invariant in `media_multipart_uploads`. Signing, completion, and abort requests carry only the opaque server-issued upload ID plus the required part metadata or provider receipts.

1. `InitiateMultipartUploadAction` authorizes an identifiable actor, validates disk, type, size, checksum and configured bounds, creates a random canonical object key, persists the session, then initiates the provider upload.
2. `SignMultipartPartAction` reloads the actor-owned active session by ID and requires the exact part number, byte length, and lowercase SHA-256 checksum.
3. The client uploads parts directly to object storage.
4. `CompleteMultipartUploadAction` takes the central session lock, rechecks state under a row lock, verifies every signed part, and requires provider path, object identity, size, and SHA-256 to match persisted state.
5. `AbortMultipartUploadAction` accepts the opaque upload ID, takes the same lock, and idempotently aborts an incomplete session.

Completion is idempotent by persisted session ID. Repeating a completed request returns the original media row without completing the provider upload again. If the provider completed but its response was interrupted, a recoverable gateway inspects the random object and resumes database finalization.

Every completed direct upload enters `pending_scan`, regardless of ordinary scanner defaults. An out-of-band scanner must call `FinalizeMediaScanAction` with `MediaScanResultData`, including clean/rejected state plus attested MIME, extension, size, SHA-256, and diagnostics. Only an exact clean attestation makes the media available and dispatches variations; a rejection or technical mismatch quarantines it.

Multipart limits include session duration, minimum/maximum part size, maximum parts, maximum object size, and central lock bounds. Schedule `nvl:media:multipart:prune` to idempotently abort expired provider uploads. Enable multipart in production only after the package’s PostgreSQL/Redis/S3 integration test passes against the target provider and `nvl:media:doctor --production --strict` reports a recoverable gateway, central lock store, and real scanner.

Run the production-stack proof locally against disposable PostgreSQL data,
shared Redis, and the target S3-compatible provider. The command creates Media
tables in the selected database and may create the configured bucket, so never
point it at an application database or shared production bucket.

```bash
DB_CONNECTION=pgsql \
DB_HOST=127.0.0.1 \
DB_PORT=5432 \
DB_DATABASE=nvl_media_production_test \
DB_USERNAME=nvl \
DB_PASSWORD=nvl \
REDIS_HOST=127.0.0.1 \
REDIS_PORT=6379 \
MINIO_ENDPOINT=http://127.0.0.1:9000 \
MINIO_BUCKET=nvl-media \
MINIO_ACCESS_KEY=minioadmin \
MINIO_SECRET_KEY=minioadmin \
composer test:media-production
```

Omit the `MINIO_*` overrides when the documented local defaults match. The test
must report one passing test; a skip is not a production-stack proof.

## Lifecycle states

Media persists `pending_upload`, `pending_scan`, `quarantined`, `available`, `processing_variations`, `failed`, and `deleted`. Only available or variation-processing media is usable. Failures retain bounded diagnostics for privileged operators; public DTOs omit scanner, quarantine, uploader, digest, disk, folder, and internal path details.

## Reusable public assets

Public uploads deduplicate globally by content digest, disk, and visibility. Reuse an existing public asset without copying or uploading its physical object:

```php
$media = $campaign->reusePublicMedia(
    media: $libraryAsset,
    collection: 'hero',
    metadata: ['placement' => 'homepage'],
);
```

Private media cannot enter this API. A reused public asset attached to multiple owners is protected from ordinary global deletion. Remove it through an owner lifecycle method to detach only that usage, or call `DeleteMediaAction::execute($media, force: true)` for an intentional administrative global delete.

A public asset with multiple associations cannot be changed to private. Detach it to a single owner or create an explicit private copy first; this prevents a shared URL from silently changing its access contract for other consumers.

The first successful public upload owns canonical file-level tags and metadata. Later digest matches reuse that asset without mutating canonical metadata; placement-specific information belongs on association metadata.

## Private files and uploader ownership

Private deduplication is scoped by both uploader type and uploader identifier. Authenticated Eloquent actors are stored polymorphically in `uploaded_by_type` and `uploaded_by`; integer, UUID, ULID, and string identifiers are supported.

Anonymous private uploads do not deduplicate by default:

```php
'deduplication' => [
    'allow_anonymous_private' => false,
],
```

Use `uploader()` for the polymorphic relation. The package has no concrete user-model relationship or fallback uploader model.

## Direct Actions

Application services may use explicit package actions:

```php
$media = app(UploadMediaAction::class)->execute(
    file: $request->file('file'),
    disk: 'private',
    model: $owner,
    slot: (new MediaSlot('contract'))->oneToOne(),
    fileName: 'contract.pdf',
    isPublic: false,
);

$association = app(AttachMediaAction::class)->execute(
    media: $media,
    model: $owner,
    collection: 'contract',
);
```

Contracts are bound for the complete library facade plus upload, attach, detach, delete, and public reuse. Actions own mutation transactions; filesystem operations live behind media gateways/operators.

## Owner-slot workflows

Application document concerns should declare policy and delegate lifecycle work
to the four actor-aware owner-slot Actions. A KPO-style private PDF slot becomes:

```php
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Models\Media;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Traits\InteractsWithNvlMedia;

final class Report extends Model implements HasNvlMedia
{
    use InteractsWithNvlMedia;

    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        $media->slot('document')
            ->oneToOne()
            ->acceptsMimeTypes(['application/pdf'])
            ->maxFileSize(4 * 1024 * 1024);
    }
}
```

The host creates `MediaActorData` from its authenticated principal and injects
the Actions rather than querying associations or copying custom properties:

```php
use Nvl\Media\Actions\ClearOwnerMediaSlotAction;
use Nvl\Media\Actions\CopyOwnerMediaSlotAction;
use Nvl\Media\Actions\GetOwnerMediaSlotAction;
use Nvl\Media\Actions\ReplaceOwnerMediaSlotAction;
use Nvl\Media\Data\MediaActorData;

$actor = new MediaActorData($user->getMorphClass(), (string) $user->getKey());

$current = $getOwnerMediaSlot->execute($actor, $report, 'document');
$document = $replaceOwnerMediaSlot->execute(
    $actor,
    $report,
    'document',
    $stagedMediaId,
    $requestId,
);
$copy = $copyOwnerMediaSlot->execute(
    $actor,
    $otherReport,
    'document',
    $document->id,
    $copyRequestId,
);
$clearOwnerMediaSlot->execute($actor, $report, 'document', $clearRequestId);
```

`GetOwnerMediaSlotAction` requires `View`; mutations require `Associate`.
Replacing with an existing private asset accepts an unassociated upload owned by
the actor, actor-owned staging associations, an authorized reusable public
asset, or an explicitly authorized `ManageStaging` adoption. A custom
`fileAcceptor` cannot be re-run for an already-persisted replacement because the
original `UploadedFile` is unavailable; upload directly into that slot instead.
Copy materializes and verifies the source, then passes a real `UploadedFile`
through canonical destination ingestion, so its custom acceptor is enforced.

Shared previous assets are detached and retained while referenced elsewhere.
Orphaned exclusive assets are deleted through the package lifecycle after the
real root transaction commits. Copy always creates a new Media identity, applies
the destination disk/visibility, preserves normalized tags, attributes the new
row to the actor, and copies only scalar keys allowlisted by
`nvl-media.owner_slots.copy.metadata_keys`. Add domain presentation/provenance keys
such as `format` explicitly; never allowlist credentials, provider payloads,
storage identity, redaction state, or association metadata.

Idempotency keys are optional UUIDs. Reuse the same key for retries of the exact
actor/owner/slot/payload; mismatched reuse fails closed. Completed results replay
without lifecycle effects, including after a newer request occupies the slot.
The default same-connection ledger completes atomically with Media. A dedicated
ledger connection uses immutable recovery checkpoints and remains retry-safe,
but is a saga boundary. Workflows respect consumer-owned outer transactions:
files, events, and split-ledger completion follow the actual root commit, while
rollback keeps the prior slot and makes the operation retryable.

Run `php artisan nvl:media:owner-slots:prune` on a schedule. Doctor validates
the ledger schema/indexes and lifecycle bounds, atomic mutation-lock support,
and up to 100 owner type/slot pairs observed in the retained ledger.

## Localized metadata

`Media` uses `nvl/translatable` for `title`, `alt`, `caption`, and `description`:

```php
$payload = UpdateMediaPayload::validateAndCreate([
    'translations' => [
        'en' => [
            'title' => 'Front view',
            'alt' => 'Blue shirt viewed from the front',
        ],
        'bg' => [
            'title' => 'Изглед отпред',
        ],
    ],
    'translationMode' => 'patch',
]);

$media = app(UpdateMediaMetadataAction::class)->execute($media, $payload);
$alt = $media->translated('alt', 'bg');
```

Patch preserves omitted locales; replace removes omitted locales. Filename, digest, disk, path, MIME type, size, visibility, tags, and technical metadata remain canonical.

Media automatically registers as `media.assets` in the central `TranslationResourceRegistry`.

Construct mutation DTOs through Laravel Data validation or another trusted boundary before invoking Actions.

## Variations and optimization

Slots can define conversion presets:

```php
$media->slot('gallery')
    ->publicReusable()
    ->addConversion('thumb', fn (ConversionDefinition $conversion) => $conversion
        ->fit('crop', 300, 300)
        ->format('webp')
        ->quality(82));
```

One upload may add or override named definitions:

```php
$article->nvlMedia()->add($file, 'gallery')
    ->withVariations([
        'card' => ['width' => 640, 'height' => 360, 'fit' => 'crop', 'format' => 'webp'],
        'zoom' => (new ConversionDefinition('zoom'))->width(1600)->format('webp'),
    ])
    ->slot();
```

Definitions are normalized and persisted on the media row, so replacement and regeneration reproduce them. Label precedence is upload override, model, slot, then global. For a deduplicated shared asset, identical definitions are idempotent and new labels may be added; conflicting definitions for an existing label fail explicitly. `withoutVariations()` suppresses every automatic variation dispatch for that terminal upload. Asset delivery accepts named variations only and rejects arbitrary width, height, fit, quality, and format parameters.

The published configuration ships with:

- `thumb`: an exact `160×160` cropped WebP.
- `small`: proportional WebP bounded to `480×480`, without upscaling.
- `medium`: proportional WebP bounded to `960×960`, without upscaling.
- `optimized`: proportional WebP bounded to `1200×1200`, without upscaling.

Presets and format profiles use `ImagePreset`, `ImageFit`, `ImageFormat`, and `ImageCompression` enums. WebP defaults to quality 82 and AVIF to 60; both are application settings, not universal targets. Lossless mode maps to quality 100 because that is the stable compression control exposed by the supported Spatie Image drivers. Unsupported formats fail during configuration/processing instead of silently preserving a misleading extension.

The default variation key is `<source-hash>-<label>.<extension>` for upgrade compatibility. A dimension-bearing pattern such as `{basename}--{label}-{width}x{height}.{extension}` is available. Labels and resolved filenames are validated before storage. Changing the naming pattern requires regenerating variations.

Run workers when conversions are queued. Variation generation is idempotent and missing association-driven conversions are regenerated when required.

See [Image variations and queues](docs/images-and-queues.md) for the complete configuration, encoder requirements, proportional-sizing behavior, custom presets, worker topology, retry rules, and deployment procedure.

## S3-compatible storage

Set `NVL_MEDIA_FILESYSTEM_DISK=s3`, configure Laravel's `filesystems.disks.s3`, add `s3` to `nvl-media.allowed_disks`, and leave the package asset routes enabled. The package streams remote sources into bounded temporary local files for image processing, writes results back through Flysystem, verifies source checksums, avoids S3 folder-marker objects, and preserves visibility during cross-disk copies.

S3 objects are private at rest by default—even when the Media record is public and reusable. Public means the record may be shared and served through the package's public delivery policy; it does not mean `public-read` ACL. This works with modern Bucket Owner Enforced buckets. Set `NVL_MEDIA_S3_USE_ACL_VISIBILITY=true` only when the bucket intentionally supports object ACLs.

See [S3 and object storage](docs/s3.md) for IAM, bucket, endpoint, URL, cache/CDN, checksum, multipart, and operational guidance.

## URLs and delivery

Use media URL/path APIs rather than concatenating disk paths:

```php
$url = $media->buildUrl(['v' => 'thumb']);
$publicUrl = $media->buildPublicUrl(['v' => 'thumb']);
$temporaryUrl = $media->getTemporaryUrl(now()->addMinutes(5));
$nullableUrl = Media::urlIfExists($media);
```

The package separates public and signed/private routes, allowlists named variation parameters, emits public/private cache headers, and supports local or remote public delivery. Central public URLs include a content version so immutable caches are safe across replacement and regeneration. Original and variation responses use distinct ETags and honor normal, weak, wildcard, and comma-separated `If-None-Match` values.

Private URL generation is fail-closed: it returns a temporary signed package route or a temporary disk URL and never falls back to an unsigned object URL. Keep the private asset route enabled unless every private disk supports temporary URLs.

Route controls:

```php
'routes' => [
    'api_enabled' => false,
    'api_prefix' => 'nvl/api/v1',
    'api_middleware' => ['api'],
    'management_middleware' => ['auth', 'throttle:60,1'],
    'assets_enabled' => true,
    'assets_prefix' => 'nvl/media',
],
```

Applications may add Sanctum, verification, permissions, response envelopes, or throttles. No named host middleware is required.

## Global administrators and Spatie Permission

The default `MediaAuthorization` grants cross-owner mutations only to the owning uploader. If the authenticated model exposes Spatie Permission's `hasAnyRole` and permission-checking methods, Media can add explicit cross-owner grants without requiring `spatie/laravel-permission` as a package dependency:

```php
'authorization' => [
    'spatie_permission' => [
        'enabled' => true,
        'global_roles' => ['admin', 'super-admin'],
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
```

`NVL_MEDIA_GLOBAL_ROLES=admin,super-admin` is the environment shortcut for the role list. Role names are empty by default, so installing an authorization package cannot silently elevate a pre-existing role during an upgrade. The `nvl.media.manage` permission grants every Media ability; the `*-any` permissions are granular. `nvl.media.manage-staging` is the narrow cross-owner grant used when an owner-slot workflow adopts another actor's staged asset. Missing roles, permissions, guard mismatches, or an absent Spatie package fail closed and normal ownership policy continues.

Global access bypasses uploader ownership, including list scoping and private delivery, but does not bypass shared-asset reference integrity, scanner quarantine, mutation locks, or storage verification. Applications may disable the bridge or replace `MediaAuthorization` for a fully custom policy.

## Retrieval and lifecycle

```php
$gallery = $article->nvlMedia()->all('gallery');
$first = $article->nvlMedia()->first('gallery');
$article->nvlMedia()->detach($media, 'gallery');
$article->nvlMedia()->clear('gallery');
```

Shared media is detached while another owner still uses it and physically deleted only after its last association disappears. Soft-deleting an owner preserves media associations for restoration; force deletion follows configured cleanup behavior.

`MediaQueryService` provides filtered, paginated administrative reads and visibility scoping. Public media is visible; private media is limited to its typed uploader unless the actor has a configured management ability or global role.

## Operations

```bash
php artisan nvl:media:doctor --production --strict --format=json
php artisan nvl:media:adopt-spatie --source=media_spatie_legacy --format=json
php artisan nvl:media:reconcile --production --disk=s3 --orphans
php artisan nvl:media:regenerate --dry-run --preset=thumb --disk=s3
php artisan nvl:media:migrate-disk --from=public --to=s3 --dry-run
php artisan nvl:media:multipart:prune --limit=500
php artisan nvl:media:owner-slots:prune --days=7 --chunk=500
```

Run storage health before and after a disk migration. Back up metadata and verify target-disk credentials before production moves.

`nvl:media:reconcile` is read-only by default. `--orphans` inventories paginated unreferenced objects below `nvl-media.root_folder`; `--cleanup-orphans` is the explicit deletion switch, `--older-than` protects recent work, and production cleanup additionally requires `--force`. Objects with unreliable age remain report-only. Files referenced only by soft-deleted media are candidates while their database tombstones remain. Disk migration supports dry run, association scopes, records-only moves, and copy verification.

Every option, safety rule, exit status, and production sequence is documented in [Command reference](docs/commands.md).

## Database schema and adoption

### Optional tenant ownership

Installing Media also installs the inert `nvl/tenancy` library; tenancy remains
disabled until the host explicitly selects and adopts the Media family. Tenant
assets, associations, variations, translations, multipart sessions, and
owner-slot operations use the canonical owner tenant. Platform catalog grants
authorize copy only: an import creates independent bytes, UUIDs, rows, and
immutable provenance, and later revocation or source deletion does not change a
committed tenant copy.

Run adoption only in maintenance from a reviewed mapping through
prepare → bounded backfill → verify → activate. An interrupted run may resume
after source or schema repair consistent with its immutable mapping. If the
mapping changes, restore the pre-cutover backup and prepare a new reviewed run.
Do not treat dropping ownership columns as rollback once duplicate names or
copied assets exist. Cleanup is package-owned and bounded; verify physical paths
before deleting any source or imported object.

Package-owned media, association, variation, multipart-session, owner-slot-operation, and translation rows use UUID primary keys. Uploader and owner morph identifiers are strings so integer, UUID, ULID, and string application keys remain compatible. The clean create migrations include composite indexes for visibility, uploader, disk, type and status listings by creation time; multipart indexes cover actor history, status/expiry, and completed media. The owner-slot ledger uniquely indexes UUID idempotency keys and indexes owner/slot and creation-time lookups. Its connection, table, processing lease, retention, and pruning chunk are configured under `nvl-media.owner_slots.idempotency`; Doctor checks its schema, lifecycle bounds, atomic lock store, and observed model/slot registrations. Expired processing attempts recover under a new operation UUID so stale workers cannot complete the replacement claim. A custom ledger connection is a recoverable saga boundary rather than a cross-database atomic transaction. Schedule `nvl:media:owner-slots:prune` to remove only expired terminal claims in bounded chunks.

Set `nvl-media.migrations.enabled=false` only while staging a legacy table whose canonical name would collide with the package migration. Rename that source, create the package schema, then run `nvl:media:adopt-spatie` without `--apply`. The command maps standard Spatie ownership columns into associations, preserves UUIDs or derives stable UUIDs from integer identifiers, accepts optional translation and variation tables, verifies every backing path, and reports source/matched counts. `--apply` is refused until the dry run has no mapping or path errors; it never drops the staged source tables and is idempotent by deterministic identifiers.

For in-place adoption, `nvl-media.root_folder` must describe the physical object layout, not the desired future layout. When persisted `folder` values already contain the complete path below the disk root, set `NVL_MEDIA_ROOT_FOLDER=` (empty) before dry-run and cutover. Otherwise physically move objects through `nvl:media:migrate-disk` and reconcile them. Doctor samples live Media rows against storage and reports root-folder drift before URLs are enabled. Soft-deleted diagnostic tombstones are excluded because canonical deletion may intentionally remove their objects.

A strict `storage.persisted_paths` failure for a live Media row is a data incident. Run read-only
Doctor and `nvl:media:reconcile --production --orphans`, verify disk/root/path,
hash, backups, and associations, and restore the original object when possible.
Use the relocation/migration API for intentional moves; never edit paths
directly or automate `--cleanup-orphans` to erase legacy discrepancies. An
unrecoverable object requires an explicit business decision before records or
associations are removed. See [the recovery runbook](docs/commands.md#missing-binary-incident-recovery).

## Public and privileged DTOs

Use `PublicMedia` for public rendering. It exposes safe identity, type, localized copy, MIME/extension, URLs, responsive image sizes, and basic file size. It never exposes storage identity or security-boundary fields.

Authorized management APIs use privileged Data projections such as `MediaLibraryItem` and `MediaManagementData`. Keep management routes disabled unless the application intends to expose disk, folder, digest, uploader, association, and internal metadata to that authorized role.

## Configuration checklist

- Define every usable filesystem disk and set `allowed_disks`; the allowlist applies to both HTTP and direct action uploads.
- Register only permitted associable model classes for API attachment. An empty `allowed_associable_types` list disables all API association mutations.
- Choose private/public delivery and signed URL lifetimes.
- Configure queue workers for variation generation.
- Keep queue `retry_after` (or SQS visibility timeout) greater than the longest Media job timeout.
- Use Redis or another central atomic lock store for `mutation_lock`, `deduplication_lock`, and multipart session locks in multi-node deployments.
- Run `nvl:media:doctor --production --strict` after changing disks, encoders, presets, sources, locks, scanner, multipart, or queue settings.
- Keep SVG scanning and public-asset deletion protection enabled.
- Keep `file_types` to the smallest `extension => string|list<string>` server-detected MIME allowlist the application needs.
- Configure `nvl-media.content_scanner` with a real `MediaContentScanner` for untrusted uploads. The explicit default is a development-only no-op scanner.
- Keep S3-compatible disks private at rest with `throw=true`.
- Keep multipart disabled unless the application needs direct uploads; when enabled, require the recoverable gateway, central locks, scanner attestation, pruning schedule, production doctor, and provider integration test.
- Disable package API routes if the application owns its own controllers.
- Run all migrations before accepting uploads.

## TypeScript, agent skill, and quality

Media DTOs and enums register with Core's Data provider under `Nvl.Media.*`:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

`nvl-media-skills` publishes current agent guidance into `.agents/skills/nvl-media`.

```bash
composer install
composer quality
```

`composer quality` verifies the manifest, Pint formatting, PHPStan at maximum strictness, direct dependency declarations, generated TypeScript freshness and compilation, and the isolated Testbench/Pest suite. Run Composer and npm security audits separately against their lockfiles. Compatibility verification covers Laravel 13/Testbench 11 on the suite's PHP 8.4+ runtime. The local production integration command additionally requires PostgreSQL, Redis, and MinIO or equivalent S3-compatible storage.

The documentation coverage test keeps facade, trait, adder, slot, conversion, model-helper, management-route, contract, event, and top-level configuration references synchronized with their public source surfaces.

## License

Released under the [MIT License](LICENSE).

## Shared owner identity

Declare a model once in `config/nvl-core.php`:

```php
'owners' => [Article::class],
```

Enable this package capability separately in `config/nvl-media.php`:

```php
'allowed_associable_types' => [Article::class],
'associable_mutation_abilities' => [Article::class => 'update'],
```

Owners implement HasNvlMedia and use InteractsWithNvlMedia; declare slots through registerNvlMedia() and access them through nvlMedia(). Preserve collection/slot declarations and authorize the configured mutation ability. Core registration does not add the model to this package's allowlist.

Laravel's `getMorphClass()` determines stored identity. These class declarations do not install host morph maps. Keep resolvers, handlers and authorization independent; use `nvl:doctor --strict --format=json` to review legacy alias mismatches or stored identity drift. See [UPGRADING.md](UPGRADING.md) before changing the host's morph map.

## Queue inheritance and shared diagnostics

`nvl-media.queue.connection` defaults to `null`. Media jobs use an explicit Media value first, then `nvl-core.queue.connection`, then Laravel's effective `queue.default`. `NVL_MEDIA_QUEUE_CONNECTION` remains an explicit configuration input. An explicit `sync` at the selected layer keeps synchronous processing; an empty string is invalid. Runtime configuration and cached configuration use the same resolution.

Run `php artisan nvl:doctor --strict --format=json` to aggregate the checks from loaded package providers, or `php artisan nvl:media:doctor --strict --format=json` for the Media-specific report. Production workers still require an appropriate host deployment and queue configuration.


## Shared infrastructure options

`nvl-media.queue.name` also defaults to null: it inherits `nvl-core.queue.name`, then the queue name configured for the selected Laravel connection. Set `NVL_MEDIA_QUEUE=media` or `nvl-media.queue.name = 'media'` when workers intentionally consume a dedicated Media queue. Existing explicit published names remain overrides.

Media locks use `nvl-media.locks.store`, then `nvl-core.locks.store`, then `cache.default`. Independent `locks.mutation.store`, `locks.deduplication.store`, and `locks.multipart.store` overrides preserve operation-specific stores. Historical `mutation_lock.store`, `deduplication_lock.store`, and `multipart.lock.store` remain supported for one major cycle and are reported by Doctor. Their duration/wait settings remain operation-specific.

## Next major: isolated schema identities

Use `nvl-media.tables.<logical-key>` for every table and `nvl-media.connection` for its database connection. Null connection inherits `nvl-core.connection`, then Laravel's default. Tables are resolved at runtime by the package table definition helper.

| Logical key | New default | Previous name |
| --- | --- | --- |
| `media` | `nvl_media_media` | `px_media` |
| `associations` | `nvl_media_associations` | `px_media_associations` |
| `image_variations` | `nvl_media_image_variations` | `px_media_image_variations` |
| `i18n` | `nvl_media_i18n` | `px_media_i18n` |
| `multipart_uploads` | `nvl_media_multipart_uploads` | `px_media_multipart_uploads` |
| `owner_slot_operations` | `nvl_media_owner_slot_operations` | `px_media_owner_slot_operations` |
| `tenant_grants` | `nvl_media_tenant_grants` | `px_media_tenant_grants` |
| `tenant_grant_locks` | `nvl_media_tenant_grant_locks` | `px_media_tenant_grant_locks` |
| `tenant_adoption_copies` | `nvl_media_tenant_adoption_copies` | `px_media_tenant_adoption_copies` |

Migration filenames contain `nvl_media_`. Existing installations must complete the upgrade in `UPGRADING.md` before running new migrations. A pending creator rejects an existing target before that owned migration runs; use `nvl:schema:preflight` for an explicit whole-batch check; legacy storage with old history needs an ownership decision.

Owned cache and lock identities follow `nvl:<package>:<purpose>:…`. Mutation, deduplication, and multipart locks use `nvl:media:mutation:`, `nvl:media:deduplication:`, and `nvl:media:multipart:`. File existence entries use `nvl:media:file-existence:` instead of `media_exists:`. Invalidation touches only the owned entry; generic host keys are never read, copied, or removed. See [UPGRADING](UPGRADING.md) for coordinated worker and lock lease cutover.

## Canonical configuration ownership

Use `nvl-media` settings in `config/nvl-media.php` and canonical package environment names. Old generic roots are foreign unless an upgrading NVL host explicitly selects them in Core's default-off compatibility. Canonical false/null/empty values win; no old roots are populated or written back. Keep logical package/resource IDs unchanged. Review [Core's rename inventory and cache/worker cutover](https://github.com/nvl-laravel-suite/core/blob/main/UPGRADING.md#major-5-canonical-configuration-and-environment).
