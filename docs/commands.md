# Command reference

All Media commands return `0` on success and non-zero when validation, diagnostics, storage, processing, or safety checks fail.

## `nvl:media:doctor`

Read-only installation and deployment diagnostics:

```bash
php artisan nvl:media:doctor
php artisan nvl:media:doctor --production --strict
php artisan nvl:media:doctor --strict --format=json
```

- `--production`: enforces production schema/index, disk, remote-source, scanner, lock, queue, route, and multipart requirements.
- `--strict`: treats failed warnings as failures.
- `--format=text|json`: human table or machine-readable output.

Checks package tables/columns/indexes, allowlisted disks and exception-enabled writes, representative persisted object paths under the configured root folder, local/public delivery, integrity policy, image drivers/encoders, durable queue timing, management-route authentication, remote cURL/bounds, multi-node locks, authorization/scanner bindings, and multipart recovery/attestation. Disabled multipart is valid; enabled multipart must use a recoverable gateway, central locks, and scanner attestation. Run it after configuration cache is built and before accepting traffic.

## `nvl:media:adopt-spatie`

Maps a staged Spatie-style media table into package media, association, translation, and variation rows. It is a non-mutating dry run unless `--apply` is explicit:

```bash
php artisan nvl:media:adopt-spatie --source=media_spatie_legacy --format=json
php artisan nvl:media:adopt-spatie \
  --source=media_spatie_legacy \
  --translations=media_translations_legacy \
  --variations=media_variations_legacy \
  --uploader-type='App\\Models\\User' \
  --apply
```

- `--source=`: staged legacy media table. It must differ from the configured canonical media table.
- `--translations=` / `--variations=`: optional staged companion tables with `id` and `media_id`; variations must expose a persisted `storage_path` or `path`.
- `--uploader-type=`: fallback morph type only when a legacy row already has `uploaded_by` but no type.
- `--locale=en`: fallback locale for translation rows without one.
- `--apply`: insert deterministic, idempotent target rows. The source tables are never changed or dropped.
- `--format=text|json`: reconciliation table or machine-readable result.

The importer accepts UUID identifiers directly and derives stable UUIDs for integer/string legacy keys. It maps `model_type`, `model_id`, and `collection_name` into package associations; carries lifecycle, visibility, uploader, metadata, translations, and variations; computes missing digests from backing objects; and reconciles source versus matched target counts. Apply is refused when any row cannot map or any original/variation object is missing.

Standard Spatie paths map to `<legacy id>/<file_name>`. If adopted rows already store complete disk-relative folders, set `MEDIA_ROOT_FOLDER=` before dry-run. If the desired target uses the default `media` prefix, move and verify the physical objects first rather than changing metadata alone.

## `nvl:media:reconcile`

Storage and delivery verification. It is read-only by default:

```bash
php artisan nvl:media:reconcile --disk=s3 --sample=250
php artisan nvl:media:reconcile --production --disk=s3 --orphans
php artisan nvl:media:reconcile --disk=s3 --live-write --cleanup
php artisan nvl:media:reconcile --disk=s3 --cleanup-orphans --older-than=1440
php artisan nvl:media:reconcile --production --disk=s3 --cleanup-orphans --older-than=1440 --force
```

- `--disk=`: disk to inspect; defaults to `media.disk`.
- `--sample=50`: maximum media records to inspect; must be positive.
- `--public-private-routes` / `--routes`: verify both route-backed delivery contracts.
- `--include-trashed`: include soft-deleted media.
- `--orphans`: inventory paginated objects below `media.root_folder` that are not referenced by live media.
- `--cleanup-orphans`: explicitly delete only age-eligible orphan candidates.
- `--older-than=1440`: minimum candidate age in minutes; recent objects remain protected.
- `--force`: required with `--cleanup-orphans` in production.
- `--require-records`: fail when the selected disk has no rows.
- `--production`: enables route verification and record requirement, prohibits live-write probes, and requires `--force` for orphan cleanup.
- `--live-write`: create/read/delete a temporary health object. Never combine with production.
- `--cleanup`: explicitly remove the temporary probe.
- `--no-write`: assert read-only operation; incompatible with `--live-write`.

Use live writes only in a controlled non-production probe. Reconciliation remains read-only unless `--live-write` or `--cleanup-orphans` is explicit. Objects with unavailable/unreliable age metadata are always retained and reported. Objects referenced only by soft-deleted media are cleanup candidates, but their database tombstones remain for diagnosis.

### Missing-binary incident recovery

A strict Doctor failure for `storage.persisted_paths` is a data incident, not
permission to delete database records. Start with read-only diagnostics:

```bash
php artisan nvl:media:doctor --strict --format=json
php artisan nvl:media:reconcile --production --orphans
```

Verify the configured disk and `root_folder`, the row's persisted folder and
hash, backups or object-store history, and every association/current use. Restore
the original object whenever possible. An intentional relocation must use the
Media relocation or migration API; never update a persisted path directly. If
the binary is unrecoverable, require an explicit business decision before
removing associations or the media record. Never automate
`nvl:media:reconcile --cleanup-orphans` as a replacement for legacy cleanup.

## `nvl:media:multipart:prune`

Idempotently aborts unfinished provider uploads that require cleanup and records their persisted session as `aborted`:

```bash
php artisan nvl:media:multipart:prune
php artisan nvl:media:multipart:prune --limit=500
```

- `--limit=500`: maximum cleanup candidates processed in one invocation.

Candidates include expired `initiated`/`completing` sessions, legacy sessions already marked `expired`, and failed initiations whose provider cleanup remains pending. Successful cleanup clears encrypted provider state and records the terminal reason. Run this on a schedule wherever multipart sessions are enabled and the configured recoverable gateway and central lock store are available. Provider cleanup failures remain retryable, are logged, and produce a non-zero exit status.

The host owns that schedule. When `media.multipart.enabled=true`, use:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('nvl:media:multipart:prune')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();
```

Multipart schedule readiness is irrelevant while multipart is disabled.

## `nvl:media:regenerate`

Regenerates configured variations:

```bash
php artisan nvl:media:regenerate --dry-run
php artisan nvl:media:regenerate --preset=thumb --preset=medium --disk=s3 --force
php artisan nvl:media:regenerate --preset=thumb --sync --force
```

- `--type=image`: media type filter; images are the conversion-capable default.
- `--disk=`: storage disk filter.
- `--preset=*`: one or more configured labels; omitted means all enabled presets.
- `--after=Y-m-d`, `--before=Y-m-d`: inclusive created-at bounds.
- `--dry-run`: count and report without dispatching or writing.
- `--sync`: process inline; intended for small controlled batches.
- `--force`: skip confirmation.

Without `--sync`, one batch job is dispatched and it creates unique per-media/per-preset jobs. Monitor failed jobs and worker storage/memory. Run `doctor` first whenever encoder or queue configuration changed.

## `nvl:media:migrate-disk`

Moves objects and updates media disk/folder metadata:

```bash
php artisan nvl:media:migrate-disk --from=public --to=s3 --dry-run
php artisan nvl:media:migrate-disk --from=public --to=s3
php artisan nvl:media:migrate-disk --from=old --to=s3 --records-only --dry-run
php artisan nvl:media:migrate-disk --column=folder --from=legacy --to=library --on-disk=s3 --dry-run
```

- `--from=`, `--to=`: source/destination disk or folder-prefix value.
- `--column=disk|folder`: migration dimension.
- `--on-disk=`: required scope option for folder-prefix migrations when desired.
- `--associable-type=*`, `--collection=*`: repeatable association filters.
- `--records-only`: update database disk metadata without physical copy; use only after an independently verified external copy.
- `--dry-run`: inspect scope without writes.
- `--from-path=`: retained as a rejected legacy option; physical moves require a configured source disk.

Cross-disk copies stream data and verify the copied object before changing database metadata. Association filters select media rows, not individual associations; shared rows move globally. Back up metadata, run dry-run, reconcile the source, perform the migration, then reconcile the destination.

## Safe production sequence

```bash
php artisan nvl:media:doctor --production --strict --format=json
php artisan nvl:media:reconcile --production --disk=public --orphans
php artisan nvl:media:migrate-disk --from=public --to=s3 --dry-run
php artisan nvl:media:migrate-disk --from=public --to=s3
php artisan nvl:media:reconcile --production --disk=s3 --orphans
php artisan nvl:media:regenerate --disk=s3 --dry-run
php artisan nvl:media:regenerate --disk=s3 --force
```

Do not combine an object migration, naming-pattern change, full regeneration, and legacy cleanup into one irreversible deployment.
