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

Checks package tables/columns/indexes, allowlisted disks and exception-enabled writes, local/public delivery, integrity policy, image drivers/encoders, durable queue timing, management-route authentication, remote cURL/bounds, multi-node locks, authorization/scanner bindings, and multipart recovery/attestation. Disabled multipart is valid; enabled multipart must use a recoverable gateway, central locks, and scanner attestation. Run it after configuration cache is built and before accepting traffic.

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

## `nvl:media:multipart:prune`

Idempotently aborts unfinished provider uploads that require cleanup and records their persisted session as `aborted`:

```bash
php artisan nvl:media:multipart:prune
php artisan nvl:media:multipart:prune --limit=500
```

- `--limit=500`: maximum cleanup candidates processed in one invocation.

Candidates include expired `initiated`/`completing` sessions, legacy sessions already marked `expired`, and failed initiations whose provider cleanup remains pending. Successful cleanup clears encrypted provider state and records the terminal reason. Run this on a schedule wherever multipart sessions are enabled and the configured recoverable gateway and central lock store are available. Provider cleanup failures remain retryable, are logged, and produce a non-zero exit status.

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
