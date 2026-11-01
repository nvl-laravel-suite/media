# Media consumer recipes

Read [installation](installation.md) for exact providers, tags and migration choice, and [API](api.md) before selecting parameter order or shaped options. The consumer owns HTTP extraction/resources, policy bindings, physical disk configuration, route implementation and frontend types. Native services own the file/model lifecycle and checks. This skill does not require the consumer to use another media library's trait or API.

## Select the native operation

| Work | Surface | Retained requirement |
| --- | --- | --- |
| Upload and replace bytes | `Nvl\Media\Services\MediaUploader` | Explicit actor, physical disk, canonical owner, registered slot and file name |
| Associate/reuse/sync/clear media or slots | `MediaAttachments` | Owner admission, association policy, slot/cardinality, revision/lifecycle constraints |
| Authorized single/bulk/query reads and usages | `MediaReader` | Actor SQL visibility and native Ownership; use bounded batch APIs on list pages |
| Rename, metadata, relocate, delete | `MediaLibrary` | Privacy changes require verified relocation; keep transactional file effects |
| Multipart | `MediaMultipart` | Durable session/part identity, actor ownership, size/checksum and cleanup policy |
| Image conversions | `MediaVariations` | Registered bounded definitions, revision and source byte identity |
| Scanner completion | `MediaScan` | Attested provider/source identity; a caller cannot mark arbitrary bytes safe |
| File import and foreign-source ownership | `MediaImport`, `MediaOwnerCatalog` | Allowed sources, explicit policies and canonical owner grants |
| URL issuance | `MediaUrlResolver`, `MediaDeliveryPolicy` | Per-disk visibility/mode allowlists and actual Download authorization |
| Server-side byte resolution | `MediaAssetService` | Consumer authenticates/signature-checks; do not expose returned disk/path |
| Health/recovery | `MediaDoctor` and declared Artisan commands | Inspect real storage, queues and cleanup journals; never fake delivery readiness |

Use the fully qualified namespaces in [API](api.md) for value objects, exceptions and adapter interfaces. Model/association metadata is not automatically a safe HTTP response.

## Bind policy and configure storage

Bind `Nvl\Media\Contracts\MediaAuthorization::allows($actor, $ability, ?Media $media, ?Model $owner): bool` to current host rules. Use native reader visibility rules and the separate `MediaBatchAuthorization` contract for batched owner/slot admission; do not approximate them by filtering unrestricted pages afterward. An authenticated or `SystemActor::Background` identity never bypasses the configured ability. Keep real file and owner authorization independent of request field values.

Configure `filesystems.disks` with actual roots, credentials and public/CDN `url`. Configure `nvl-media` disk allowlists and per-disk delivery/visibility policy before use. The main skill contains a complete public/private/S3 delivery-policy configuration example. A private default belongs on private storage; a delivery override must be explicitly allowed. The package boots without optional cloud drivers, image/PDF/provider adapters; explicitly requested unavailable capabilities fail rather than silently choosing another path.

Choose `nvl-media.models.media` before provider boot when replacing the Media root; retain native UUID, required casts, immutable identity, listeners and effective package storage. Add consumer columns with host migrations. Do not change a selected root's table/connection independently of technical/association records.

## Register an owner and upload

Let `Article` be the host's actual Eloquent owner. Implement `HasNvlMedia` with `InteractsWithNvlMedia`, then declare slots/conversions:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Traits\InteractsWithNvlMedia;

class Article extends Model implements HasNvlMedia
{
    use InteractsWithNvlMedia;

    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        $media->slot('hero');
    }
}
```

Register the exact host owner before resource compilation. For example, add `App\Models\Article::class` under the `articles` capability in `config/nvl-core.php`:

```php
use App\Models\Article;

return ['owners' => ['articles' => Article::class]];
```

Merge this into existing Core configuration; do not replace other declarations. Alternatively call `app(\Nvl\Support\OwnerRegistry::class)->register('articles', Article::class)` from the host provider before the registry freezes. This declares a capability, not a morph-map rewrite or access grant. For an owned resource, configure and adopt the concrete model through native Ownership before serving requests. Actual slot limits, accepted MIME types, private/public requirements and conversion definitions are product decisions; declare them through the typed `MediaSlot`/conversion API instead of trusting submitted slot settings. A private exclusive hero can use `$media->slot('hero')->privateExclusive()->onlyKeepLatest(1)->useDisk('private')` in the registration method above.

```php
use Nvl\Media\MediaRegistration;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Services\MediaUploader;

$slot = MediaRegistration::forOwner($article)->getSlot('hero');
if ($slot === null) {
    throw new \LogicException('The host must register the hero slot.');
}
$media = app(MediaUploader::class)->upload(
    actor: $actor,
    file: $uploadedFile,
    disk: 'private',
    model: $article,
    slot: $slot,
    fileName: 'hero.jpg',
);
$association = app(MediaAttachments::class)->attach($actor, $media, $article, collection: 'hero', slot: $slot);
$admitted = app(MediaReader::class)->get($actor, $media->id);
```

Here `$actor` is an authenticated admitted principal, `$article` is canonical persisted storage and `$uploadedFile` is a native `UploadedFile` extracted by the consumer. The example deliberately leaves `isPublic` false and requires the host to configure the private disk/policy first. Use `replaceSlot` for a configured replacement lifecycle; do not emulate exclusive/keepLatest/private-slot writes with raw association-table updates. Map approved output into a consumer resource rather than exposing the model's storage/source internals.

Upload and association have separate transactions in this example; an attachment failure can leave the uploaded asset. If the host requires them to succeed together, wrap both calls in a transaction on the actual configured Media connection. A transaction on a different owner connection does not make the composition atomic.

## Reads, metadata and cleanup

Use `MediaReader::getMany`/`findMany` and `slots` for bounded list-page reads. Keep requested ID/owner/slot limits and native actor visibility. Select the appropriate strict-vs-finding semantics from [API](api.md). Direct model relations do not independently apply a current actor policy.

`MediaLibrary::updateMetadata` owns its field allowlist and localized title/alt/caption seam. Byte hash, scanner identity, disk/path and ownership do not become localized/client-editable metadata. `rename` and verified `relocate` retain storage and integrity checks. Visibility changes are file lifecycle operations. Deletion uses transactional cleanup scheduling and durable recovery; do not unlink files or synthesize a successful database rollback around an already published storage effect. Preserve enclosing transaction commit/rollback and worker context.

For multipart uploads, use the real native session start/part/complete/abort methods from [API](api.md); preserve immutable upload ID, owner, checksum and configured bounds. Cleanup remains available after restrictive policy changes. Variation/scanning callbacks must carry the original byte/revision identity and provider attestations, not a caller's assertion that a file is ready.

## Deliver bytes through consumer-owned routes

Use the main skill's delivery-policy example to choose direct public URLs, authenticated application delivery, signed application capabilities or supported temporary storage URLs. Implement the configured route names in the application. `getUrl`/`buildUrl` do not authorize private download or mint a capability. Signed/provider issuance requires explicit expiration within the configured maximum TTL and Download policy.

For signed application delivery verify the actual signature, expiry, asset revision and canonical owner/scope before byte resolution. Authenticate and authorize each private authenticated request. `MediaAssetService::resolve` checks asset/policy facts but is not an authentication middleware; keep disk/path server-side, preserve private/no-store headers and stream/proxy when URLs must remain masked. A redirect discloses the storage URL. Signed bearer URLs may replay until expiry; provider/CDN caching imposes external revocation limits. Provider temporary links are refused for owned private media.

Set `X-Content-Type-Options: nosniff` on every byte response. Deliver untrusted SVG/HTML and other active documents with `Content-Disposition: attachment`; deliberate inline rendering belongs on a cookieless origin with `Content-Security-Policy: sandbox; default-src 'none'` and no script/same-origin sandbox grants. Apply equivalent policy to CDN/provider responses. The scanner preserves original bytes and does not sanitize arbitrary SVG for same-origin inline rendering. Enabled SVG scanning rejects DTDs, processing instructions, XML Base overrides, obfuscated/external CSS and nested SVG data URLs; re-export affected design-tool files without these features.

### Authenticated application route

The default authenticated route name is `nvl.media.authenticated.show`; URL generation passes the asset ID as the `media` route parameter. Optional `v` selects a registered, already generated variation, not on-demand transformations. Define the configured route name with `{media}`. This unowned/per-asset example assumes the host policy can decide View and Download from the canonical asset itself:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaDeliveryMode;
use Nvl\Media\Services\MediaAssetService;
use Nvl\Media\Services\MediaReader;

Route::get('/private-media/{media}', function (Request $request, string $media) {
    $actor = $request->user();
    abort_if($actor === null, 401);
    $input = $request->validate(['v' => ['nullable', 'string', 'max:100']]);
    $asset = app(MediaReader::class)->get($actor, $media);
    abort_unless(app(MediaAuthorization::class)->allows($actor, MediaAbility::Download, $asset), 403);
    $resolved = app(MediaAssetService::class)->resolve(
        media: $asset,
        variationLabel: $input['v'] ?? null,
        mode: MediaDeliveryMode::Application,
    );

    return Storage::disk($resolved['disk'])->download($resolved['path'], null, [
        'Content-Type' => $resolved['mime_type'],
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "sandbox; default-src 'none'",
    ]);
})->middleware('auth')->name('nvl.media.authenticated.show');
```

For native owned storage, enter the verified host owner context before both issuance and reads. If the policy requires an Article, resolve its canonical identity, verify the association through native admitted slot reads, and pass it as `owner` to Download policy. Neither a submitted Article ID nor an arbitrary asset/owner pair is a grant.

With a private asset, application delivery enabled on its disk and this route registered, build its authenticated URL through the service:

```php
use Nvl\Media\Enums\MediaDeliveryMode;
use Nvl\Media\Services\MediaUrlResolver;

$url = app(MediaUrlResolver::class)->forMedia($asset, mode: MediaDeliveryMode::Application);
```

The public application route defaults to `nvl.media.assets.show` and also receives `media`. Signed delivery uses the configured private route with its signed capability query; use the issuing service and preserve every signed parameter rather than constructing URLs manually.

## Verify and operate

Use the declared command names in [installation](installation.md) and installed `--help` for doctor, reconcile, variations, disk migration and recovery options. Test rejected upload/slot/privacy input, owner/actor isolation, replacement model hooks, scanner failures, bounded bulk reads, actual delivery mode/TTL, source outer commit/rollback and cleanup replay. Optional dependency absence and real storage-provider behavior are separate checks; a fake disk does not certify S3, CDN or a production queue worker.
