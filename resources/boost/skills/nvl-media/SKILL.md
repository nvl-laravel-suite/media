---
name: nvl-media
description: Use when integrating or reviewing nvl/media uploads, multiple disks, CDN or application delivery, private files, model slots, variations, or storage lifecycle operations in Laravel.
---

# NVL Media

Requires PHP 8.4 and Laravel 13. Consumers own transport, projections and frontend types; native services retain authorization, ownership, validation, transactions and recovery.

## Read by task

- [Installation and publication](references/installation.md): provider discovery, exact config/skill/migration tags and migration ownership.
- [Consumer recipes](references/usage.md): native owner registration, authorized uploads and slots, bulk reads, delivery and lifecycle choices.
- [Public API](references/api.md): exact service signatures, option shapes, policy contracts, enum values and model capabilities. Search the relevant service before calling it.

These references travel with the skill. Choose the host's actual disks, origin/route policy, owner model and actor grants; the package cannot infer them from an upload request.

## Storage configuration

Override `config/nvl-media.php`; define physical roots, credentials and the public CDN `url` in Laravel `filesystems.disks`. This example admits only public assets on `public`, only private assets on `private`, and both on `s3`:

```php
return [
    'disk' => 'private',
    'public_disk' => 'public',
    'private_disk' => 'private',
    'allowed_disks' => ['public', 'private', 's3'],
    'root_folder' => 'media',
    'root_folders' => ['public' => 'media', 'private' => '', 's3' => 'assets/uploads'],
    'default_path' => '',
    'delivery' => [
        'disks' => [
            'public' => [
                'public' => ['mode' => 'direct', 'allowed' => ['direct', 'application']],
                'private' => false,
            ],
            'private' => [
                'public' => false,
                'private' => [
                    'mode' => 'application',
                    'allowed' => ['application', 'signed_application', 'temporary_storage'],
                ],
                'maximum_ttl' => 120,
            ],
        ],
    ],
];
```

Allowlist each disk. Explicit disk/slot choices precede visibility defaults. Logical visibility does not secure bucket ACLs: keep private storage inaccessible anonymously. Use path helpers with the actual disk. Empty prefixes allow disk-root originals; whole-disk orphan cleanup is refused. Configuration changes do not move bytes: drain multipart sessions and use verified migrations.

## Delivery

For persisted available models, resolve `Nvl\Media\Services\MediaUrlResolver` through dependency injection:

```php
use Nvl\Media\Enums\MediaDeliveryMode;

$cdn = $publicMedia->getUrl();
$masked = $publicMedia->getUrl(mode: MediaDeliveryMode::Application);
$authenticated = $privateMedia->getUrl();
$signed = $urls->signedUrl($actor, $privateMedia, now()->addSeconds(60));
$provider = $urls->temporaryUrl($actor, $privateMedia, now()->addSeconds(60));
```

`$urls` is the resolver; `$actor` is the authenticated actor or explicitly authorized system actor. Overrides must be allowed by disk policy; failures never fall back to another delivery mode. `getUrl()`/`buildUrl()` do not authorize private download or mint capabilities. Signed/provider issuance checks Download authorization and requires explicit expiry within `maximum_ttl`. Provider URLs require driver support and are refused for owned private media.

The package registers no routes. Implement the configured `assets.public_route_name`, `authenticated_route_name` and `private_route_name`. Authenticate and authorize actual Download permission for each private authenticated request. For signed application routes, verify signature, expiry, current revision and canonical owner/scope before resolving bytes. `MediaAssetService::resolve()` enforces current asset/policy facts but does not authenticate; keep returned disk/path server-side. Stream/proxy masked responses; redirects disclose storage URLs. Preserve private/no-store headers. Signed URLs are replayable bearer capabilities until expiry; provider links and cached public CDN copies have external revocation limits.

## Native operations

- `MediaUploader`, `MediaAttachments`, `MediaLibrary`: upload/replace, registered associations, metadata, verified relocation and deletion. Visibility changes require verified relocation; metadata cannot flip privacy.
- `MediaReader`: authorized queries and owner slots; retain batch SQL visibility scopes.
- `MediaMultipart`, `MediaVariations`, `MediaScan`, `MediaImport`, `MediaOwnerCatalog`: validated uploads, revision-aware processing, attested scans, imports and admitted ownership grants. Abort/discard cleanup stays available after policy changes.

Implement `HasNvlMedia`, register slots/conversions with `MediaRegistration`, and pass explicit actors. Bind host authorization contracts; `SystemActor::Background` needs an explicit grant. Keep byte/scanner identity nonlocalized; translate title/alt/caption through the package seam. Preserve canonical owner admission, scanner attestations, revision checks and transaction-aware cleanup.

## Implement a capability

Use the self-contained [consumer recipes](references/usage.md) for owner registration, private upload/slot composition and an authenticated delivery route. The [API reference](references/api.md) contains every public capability, contract and shaped argument; the [installation reference](references/installation.md) lists declared command names and publishable assets. These references do not depend on a monorepo checkout or unpublished application fixtures.

Preserve the documented disk/visibility policy, current actor and owner admission, byte/scanner identity, revisions and outer-commit cleanup when adding a consumer adapter. If the host has not chosen a disk, policy, route or provider, make that decision explicit instead of filling it with a permissive default.
