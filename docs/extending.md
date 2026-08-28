# Extension contracts and events

NVL Media keeps security and lifecycle invariants inside package Actions while exposing replaceable boundaries for application policy, scanning, search, remote DNS, multipart storage, and common mutations.

Prefer the narrowest contract that solves the integration. Replace `MediaLibraryContract` only when the application intentionally owns the complete public orchestration boundary.

## Binding an implementation

Register application bindings in a service provider:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Media\ApplicationMediaAuthorization;
use App\Media\ApplicationMediaScanner;
use Illuminate\Support\ServiceProvider;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaContentScanner;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            MediaAuthorization::class,
            ApplicationMediaAuthorization::class,
        );

        $this->app->bind(
            MediaContentScanner::class,
            ApplicationMediaScanner::class,
        );
    }
}
```

Use `bind()` for stateless implementations and `scoped()` when one instance should be reused within a request/job lifecycle. Tests may use `app()->instance()` for a fake.

## Contract index

| Contract | Default | Purpose |
| --- | --- | --- |
| `HasMedia` | Implemented by consumer models | Model capability consumed by `InteractsWithMedia` |
| `MediaLibraryContract` | `MediaLibrary` | Complete facade/injection boundary |
| `UploadMediaContract` | `UploadMediaAction` | Ordinary authoritative upload |
| `AttachMediaContract` | `AttachMediaAction` | Association creation |
| `DetachMediaContract` | `DetachMediaAction` | Association removal |
| `DeleteMediaContract` | `DeleteMediaAction` | Transaction-safe global deletion |
| `ReusePublicMediaContract` | `ReusePublicMediaAction` | Existing public-asset reuse |
| `MediaAuthorization` | `DefaultMediaAuthorization` | Stable actor/ability policy |
| `MediaContentScanner` | `NullMediaContentScanner` | Synchronous scan of exact persisted bytes |
| `MediaSearchDriver` | `PortableMediaSearchDriver` | Apply search semantics to a media query |
| `MediaHostResolver` | `SystemMediaHostResolver` | Resolve all A/AAAA results for remote ingestion |
| `MultipartUploadGateway` | Disabled gateway or configured implementation | Provider initiate/sign/complete/abort |
| `RecoverableMultipartUploadGateway` | `S3MultipartUploadGateway` when enabled | Adds completed-object recovery inspection |

The model `HasMedia` contract is not a service-container binding. Implement it and use the trait.

## Complete library replacement

The `Nvl\Media\Facades\Media` facade resolves `MediaLibraryContract`:

```php
$this->app->scoped(
    MediaLibraryContract::class,
    ApplicationMediaLibrary::class,
);
```

An implementation must preserve the interface signatures. If it delegates back to package Actions, it retains package validation and lifecycle semantics. If it replaces those Actions, the application becomes responsible for equivalent ingestion, scanning, storage verification, transaction callback, locking, revision, and cleanup guarantees.

For most applications, constructor injection is sufficient:

```php
final readonly class PublishArticle
{
    public function __construct(
        private MediaLibraryContract $media,
    ) {}
}
```

The facade remains testable because it is a container proxy:

```php
use Nvl\Media\Facades\Media;

Media::expects('findOrFail')
    ->once()
    ->with($mediaId)
    ->andReturn($media);
```

Clear or swap the facade’s resolved contract instance when a test replaces the container binding after first use.

## Focused action contracts

### `UploadMediaContract`

Replace this when the application needs additional orchestration around ordinary uploads while retaining the same caller contract.

```php
public function execute(
    UploadedFile $file,
    string $disk,
    Model $model,
    MediaSlot $slot,
    string $fileName,
    bool $isPublic = false,
    array $tags = [],
    array $metadata = [],
    ?string $folderOverride = null,
    ?bool $allowDuplicates = null,
    ?bool $deduplicateExisting = null,
    bool $skipAutoVariations = false,
    ?string $uploadedBy = null,
    ?string $uploadedByType = null,
    array $variationDefinitions = [],
): Media;
```

The package implementation treats client filename, extension, and MIME as claims. Any replacement that persists files must retain the authoritative ingestion pipeline or provide equivalent security.

### `AttachMediaContract`

```php
public function execute(
    Media $media,
    Model $model,
    string $collection = 'default',
    ?string $locale = null,
    ?int $order = null,
    array $metadata = [],
    bool $dispatchVariations = true,
): MediaAssociation;
```

Attachment is valid only for usable media and a saved target model. The package action is idempotent for the same media, morph target, collection, and locale.

### `DetachMediaContract`

```php
public function execute(
    Media|string $media,
    Model $model,
    ?string $collection = null,
): int;
```

The return value is the number of deleted associations.

### `DeleteMediaContract`

```php
public function execute(Media|string $media, bool $force = false): bool;
```

`force` means bypass the multiply-associated-public-asset guard after caller authorization. It does not skip locks, database soft deletion, post-commit cleanup, or integrity policy.

### `ReusePublicMediaContract`

```php
public function execute(
    Media|string $media,
    Model&HasMedia $model,
    string $collection = 'default',
    ?string $locale = null,
    ?int $order = null,
    array $metadata = [],
    bool $dispatchVariations = true,
): MediaAssociation;
```

Private assets must fail this boundary.

The package trait, facade, controllers, and lifecycle services all resolve these contracts consistently.

## Authorization

`MediaAuthorization` receives stable package data instead of an application user class:

```php
public function allows(
    MediaActorData $actor,
    MediaAbility $ability,
    ?Media $media = null,
    ?Model $owner = null,
): bool;
```

`MediaActorData` contains:

- Morph-compatible actor `type`.
- String/int actor `id`.
- `system` flag for trusted system work.
- `signed` flag for a private delivery capability.

Example application policy:

```php
<?php

declare(strict_types=1);

namespace App\Media;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\DefaultMediaAuthorization;

final readonly class ApplicationMediaAuthorization implements MediaAuthorization
{
    public function __construct(
        private DefaultMediaAuthorization $fallback,
    ) {}

    public function allows(
        MediaActorData $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool {
        if ($actor->system) {
            return true;
        }

        if ($ability === MediaAbility::ListAll
            && $actor->type === 'users'
            && $this->isMediaManager($actor->id)) {
            return true;
        }

        return $this->fallback->allows($actor, $ability, $media, $owner);
    }

    private function isMediaManager(int|string|null $actorId): bool
    {
        return $actorId !== null
            && in_array((string) $actorId, config('media-manager.ids', []), true);
    }
}
```

The optional Spatie-compatible bridge runs before `MediaAuthorization`. Disable `media.authorization.spatie_permission.enabled` if the consumer contract must be the only cross-owner authority.

The abilities are:

| Ability | Meaning |
| --- | --- |
| `MediaAbility::List` | Enter the scoped library |
| `MediaAbility::ListAll` | Include other actors’ private live media |
| `MediaAbility::View` | Read media metadata |
| `MediaAbility::Download` | Read the binary |
| `MediaAbility::Upload` | Create media |
| `MediaAbility::Associate` | Attach or detach |
| `MediaAbility::Mutate` | Update, rename, replace, move, or regenerate |
| `MediaAbility::Delete` | Delete |
| `MediaAbility::Reuse` | Reuse a public asset |
| `MediaAbility::ManageStaging` | Adopt another actor's staged asset through an owner-slot workflow |

## Content scanner

`MediaContentScanner` scans the exact materialized `UploadedFile` that will be persisted:

```php
public function scan(UploadedFile $file): void;
```

Throw an exception to reject the file. Return normally only for accepted content.

```php
<?php

declare(strict_types=1);

namespace App\Media;

use App\Contracts\VirusScanner;
use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Exceptions\MediaUploadException;

final readonly class ApplicationMediaScanner implements MediaContentScanner
{
    public function __construct(
        private VirusScanner $scanner,
    ) {}

    public function scan(UploadedFile $file): void
    {
        $path = $file->getRealPath();

        if ($path === false || ! $this->scanner->isClean($path)) {
            throw new MediaUploadException('The uploaded file was rejected by content scanning.');
        }
    }
}
```

The scanner interface is synchronous for ordinary uploads. Bound scanners should enforce their own timeout and fail closed. Do not log full file contents, credentials, or unbounded scanner diagnostics.

Multipart uploads use a separate out-of-band attestation step after provider completion. Submit `MediaScanResultData` to `FinalizeMediaScanAction` or `Media::finalizeScan()`; a boolean-only result is not sufficient.

## Search driver

`MediaSearchDriver` mutates the already visibility-scoped Eloquent query:

```php
public function apply(Builder $query, string $search): void;
```

Example PostgreSQL driver:

```php
<?php

declare(strict_types=1);

namespace App\Media;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Models\Media;

final class PostgreSqlMediaSearchDriver implements MediaSearchDriver
{
    /**
     * @param  Builder<Media>  $query
     */
    public function apply(Builder $query, string $search): void
    {
        $query->whereRaw(
            "to_tsvector('simple', coalesce(filename, '')) @@ plainto_tsquery('simple', ?)",
            [$search],
        );
    }
}
```

Bind it directly or set `media.query.search_driver` to its class. The driver must add constraints only; it must not remove visibility scope, pagination order, eager loads, or soft-delete constraints.

## Remote host resolver

`MediaHostResolver` returns every IP address advertised for a hostname:

```php
/**
 * @return list<string>
 */
public function resolve(string $host): array;
```

The remote source boundary independently validates every returned IPv4/IPv6 address, pins cURL to the accepted set, validates redirects, and checks the connected peer. A custom resolver is useful for deterministic tests or a controlled enterprise DNS boundary; it does not bypass the public-address policy.

```php
$this->app->bind(MediaHostResolver::class, CorporateMediaHostResolver::class);
```

Do not return an address that was not resolved and validated for the requested hostname.

## Multipart gateway

`MultipartUploadGateway` is the provider boundary:

```php
public function initiate(MultipartUploadSessionData $session): void;

public function signPart(
    MultipartUploadSessionData $session,
    SignMultipartPartData $part,
): SignedMultipartPartData;

public function complete(
    MultipartUploadSessionData $session,
    CompleteMultipartUploadData $completion,
): CompletedMultipartObjectData;

public function abort(MultipartUploadSessionData $session): void;
```

Production gateways must implement `RecoverableMultipartUploadGateway`:

```php
public function inspect(
    MultipartUploadSessionData $session,
): ?CompletedMultipartObjectData;
```

Gateway requirements:

- Use only the random persisted object key and encrypted provider state.
- Bind provider upload identity to the persisted session.
- Require the declared part length and checksum when signing.
- Return completed path, lowercase SHA-256, byte size, detected/attested MIME, and stable object identity.
- Make abort safe to retry and treat an already-absent provider upload as success.
- Recover cleanup from missing provider state by using only the persisted server-owned object key; never accept a provider upload identifier from the client.
- Make `inspect()` distinguish “not completed” from a completed object whose response was interrupted.
- Preserve TLS and provider signature verification.
- Never trust technical fields resubmitted by a client.

The first-party `S3MultipartUploadGateway` supports AWS S3 and Laravel S3-compatible adapters. Production doctor requires a recoverable gateway when multipart is enabled.

## Events

All package lifecycle events implement `ShouldDispatchAfterCommit`. They dispatch after the real outer transaction commits; rollback discards them.

### `MediaUploadedEvent`

```php
public function __construct(public Media $media);
```

Emitted after a newly created ordinary media row and its verified physical object are durable. Digest reuse of an existing row is not a new upload event.

### `MediaAttached`

```php
public function __construct(
    public string $mediaId,
    public array $association,
);
```

`association` shape:

```php
[
    'media_id' => '018f...',
    'associable_type' => 'articles',
    'associable_id' => '018f...',
    'collection' => 'gallery',
    'locale' => 'en',
]
```

### `MediaDetached`

```php
public function __construct(
    public string $mediaId,
    public array $affectedAssociations,
);
```

`affectedAssociations` is a list of the same generic association snapshot. Snapshots are carried because removed rows may no longer be queryable.

### `MediaMutated`

```php
public function __construct(
    public string $mediaId,
    public string $mutation,
    public array $affectedAssociations = [],
);
```

Current mutation names:

| Name | Emitted by |
| --- | --- |
| `metadata_updated` | Metadata/visibility update |
| `renamed` | Display filename update |
| `file_replaced` | Source replacement |
| `moved` | Folder move |
| `deleted` | Global deletion |

Treat the media UUID and mutation name as the stable integration fields. Reload the media after commit when the row still exists. For deletion, use `affectedAssociations`; the media row is soft-deleted and may require `withTrashed()` for diagnostics.

Listener example:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use Nvl\Media\Events\MediaMutated;

final class PurgeMediaCdn
{
    public function handle(MediaMutated $event): void
    {
        if (in_array($event->mutation, ['file_replaced', 'moved', 'deleted'], true)) {
            // Dispatch an application-owned durable purge job.
        }
    }
}
```

Queued listeners should use Laravel’s after-commit queue semantics as an additional safeguard. Event payloads intentionally avoid arbitrary associated models.

## Operational logs

Recoverable operational failures are emitted as structured log records. Important messages and context include:

| Message | Important context |
| --- | --- |
| `Media storage cleanup reported failure.` | `disk`, `path`, `operation`, `transaction_outcome` |
| `Media storage cleanup threw an exception.` | Cleanup context plus exception/error |
| `Media upload integrity verification failed.` | Disk/path, mismatch kind, expected/actual checksum or size |
| `Media replacement integrity verification failed.` | Media, disk/path, expected/actual integrity |
| `Media variation work became stale.` | Media, label, expected/current revision |
| `Media ingestion scanner rejected a source.` | Safe display name, MIME, size, scanner and bounded error |
| `Media scanner quarantined a direct upload.` | Media, failure code, diagnostics |
| `Media remote source was rejected.` | Host, scheme, exception, bounded error; URL credentials/path are not logged |
| `Recovered multipart object after interrupted completion.` | Session and interrupted exception |
| `Multipart completion requires recovery.` | Session and exception |
| `Multipart session failed verification.` | Session, failure code, mismatch context |
| `Multipart cleanup failed after verification rejection.` | Session and cleanup exception |

Alert on repeated integrity mismatches, scanner infrastructure failures, multipart recovery failures, or cleanup errors. Cleanup errors do not invalidate committed database state; use reconciliation to inventory residue.

## Testing extensions

For a focused fake:

```php
$scanner = Mockery::mock(MediaContentScanner::class);
$scanner->shouldReceive('scan')->once();

app()->instance(MediaContentScanner::class, $scanner);
```

For the facade boundary:

```php
$library = Mockery::mock(MediaLibraryContract::class);
$library->shouldReceive('findOrFail')
    ->with($mediaId)
    ->andReturn($media);

app()->instance(MediaLibraryContract::class, $library);
Media::clearResolvedInstance(MediaLibraryContract::class);
```

Extension tests should prove both success and fail-closed behavior:

- Authorization denies unknown actors and abilities.
- Scanners reject by throwing and do not leak temporary files.
- Search drivers preserve visibility scope.
- Host resolvers cannot smuggle private/reserved IPs.
- Multipart gateways recover interrupted completion and reject identity/integrity mismatch.
- Replaced upload/delete contracts remain honored through trait, facade, controllers, and lifecycle services.

## Related references

- [PHP API](php-api.md)
- [HTTP API](http-api.md)
- [Configuration](configuration.md)
- [Command reference](commands.md)
- [S3 and object storage](s3.md)
