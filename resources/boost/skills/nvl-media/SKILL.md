---
name: nvl-media
description: Integrate native NVL Media services, model slots, secure storage and lifecycle operations.
---

# NVL Media

Requires PHP 8.4 and Laravel 13. Consumers own data objects, Actions, controllers, routes, resources and frontend types. Use native services and retain package authorization, ownership, validation, transactions, locking and recovery.

## Storage configuration

The following is an override fragment for `nvl-media.php`; physical roots and credentials belong in Laravel filesystem configuration.

```php
return [
    'disk' => 'private',
    'public_disk' => 'public',
    'private_disk' => 'private',
    'allowed_disks' => ['public', 'private', 's3'],
    'root_folder' => 'media',
    'root_folders' => ['public' => 'media', 'private' => '', 's3' => 'assets/uploads'],
    'default_path' => '',
];
```

Allowlist every selected disk. Explicit disk and slot choices take precedence over visibility defaults. Use package path helpers with the actual disk. An empty prefix permits originals at the disk root; whole-disk orphan cleanup remains refused. Configuration changes do not relocate existing bytes: use verified migration operations and drain multipart sessions first.

## Native operations

- `MediaUploader`: upload and replace files with an explicit actor, host model and registered slot.
- `MediaAttachments`: attach, detach, reuse, sync and replace/clear/copy registered owner slots.
- `MediaLibrary`: rename, update metadata, relocate, delete and batch mutations.
- `MediaReader`: authorized pagination, usages, finite asset reads and owner slots.
- `MediaMultipart`: validated initiation, signing, completion and abort.
- `MediaVariations`, `MediaScan`, `MediaImport`: revision-aware processing, attested scan completion and explicit import.
- `MediaOwnerCatalog`: admitted native ownership grants and catalog imports.

Implement `HasNvlMedia` and register slots/conversions with `MediaRegistration`. Keep byte identity, disk, MIME, filename and scanner facts nonlocalized. Localized title/alt/caption use the package translation seam. Returned models support consumer-owned projections; expose private fields only after explicit authorization.

Bind host authorization contracts. `SystemActor::Background` requires an explicit grant. Keep batch visibility in `MediaBatchAuthorization` SQL scopes and retain canonical owner admission before hydration.

The package registers no HTTP routes. Consumers own management and signed delivery endpoints, authentication, CSRF, CORS, rate limits and response headers. Use package delivery services; do not construct private URLs from raw paths.

Use `nvl:media:doctor`, `nvl:media:reconcile`, `nvl:media:regenerate` and `nvl:media:migrate-disk` for operations. Read the shipped PHP, storage, queue, S3 and command references for exact signatures and operational bounds.
