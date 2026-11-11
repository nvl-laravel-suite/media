# Native PHP API

Use PHP 8.4 and Laravel 13. Consumers own requests, controllers, Actions, DTOs, JSON resources and frontend types. Package services own validation, explicit actor authorization, canonical owner admission, transactions, locking and recovery. Native models, enums, files, domain slots and documented arrays replace the old DTO and Action interfaces.

`SystemActor::Background` is an identity and needs an explicit policy grant. Human actors are persisted `Authenticatable` models; the service checks their current identity. Bind the package authorization contracts before user-driven operations.

## Native upload example

Implement `HasNvlMedia` on the real owner model, including its `nvlMediaAssociations(): MorphMany` relation. Register the application's slots through its hook:

```php
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;

public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
{
    $media->slot('documents')->privateExclusive()->path('documents/{model_id}');
}
```

Given a persisted owner, authenticated actor and `UploadedFile`, call native services with explicit inputs:

```php
use Nvl\Media\MediaRegistration;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaUploader;

$slot = MediaRegistration::forOwner($owner)->getSlot('documents')
    ?? throw new LogicException('Register the documents slot first.');

$asset = app(MediaUploader::class)->upload(
    actor: $actor,
    file: $file,
    disk: $slot->resolveDisk(false),
    model: $owner,
    slot: $slot,
    fileName: 'invoice.pdf',
    isPublic: false,
    allowDuplicates: true,
    deduplicateExisting: false,
    skipAutoVariations: true,
);

$association = app(MediaAttachments::class)->attach(
    actor: $actor,
    media: $asset,
    model: $owner,
    collection: 'documents',
    slot: $slot,
);
```

Upload and association are separate operations. When the host needs them to succeed together, run both in a transaction on the actual configured Media connection; a transaction on an unrelated owner connection cannot make them atomic. Consumer-owned fluent wrappers may compose these calls, but the package does not provide `nvlMedia()->add()` or an upload builder.

## `MediaUploader`

Namespace: `Nvl\Media\Services\MediaUploader`.

- `upload(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Http\UploadedFile $file, string $disk, Illuminate\Database\Eloquent\Model $model, Nvl\Media\Slots\MediaSlot $slot, string $fileName, bool $isPublic = …, array $tags = …, array $metadata = …, ?string $folderOverride = …, ?bool $allowDuplicates = …, ?bool $deduplicateExisting = …, bool $skipAutoVariations = …, array $variationDefinitions = …): Nvl\Media\Models\Media`
- `replace(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, Illuminate\Http\UploadedFile $file): Nvl\Media\Models\Media`

## `MediaAttachments`

Namespace: `Nvl\Media\Services\MediaAttachments`.

- `attach(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media $media, Illuminate\Database\Eloquent\Model $model, string $collection = …, ?string $locale = …, ?int $order = …, array $metadata = …, bool $dispatchVariations = …, bool $requirePublic = …, ?Nvl\Media\Slots\MediaSlot $slot = …): Nvl\Media\Models\MediaAssociation`
- `detach(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, Illuminate\Database\Eloquent\Model $model, ?string $collection = …): int`
- `reuse(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, string $collection = …, ?string $locale = …, ?int $order = …, array $metadata = …, bool $dispatchVariations = …): Nvl\Media\Models\MediaAssociation`
- `remove(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $mediaId, ?string $collection = …): void`
- `sync(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, Illuminate\Support\Collection|array $media, string $collection = …): void`
- `clearCollection(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, string $collection = …): void`
- `clearCollectionExcept(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, string $collection = …, Illuminate\Support\Collection|array $except = …): void`
- `deleteAll(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model): void`
- `removeFromModel(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, Nvl\Media\Models\Media|string $media, ?string $collection = …, ?Closure $beforeOrphanDelete = …): void`
- `getSlot(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $slot): ?Nvl\Media\Models\Media`
- `replaceSlot(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $slot, string $mediaId, ?string $idempotencyKey = …): Nvl\Media\Models\Media`
- `clearSlot(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $slot, ?string $idempotencyKey = …): void`
- `copySlot(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $slot, string $sourceMediaId, ?string $idempotencyKey = …): Nvl\Media\Models\Media`

## `MediaLibrary`

Namespace: `Nvl\Media\Services\MediaLibrary`.

- `rename(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, string $filename): Nvl\Media\Models\Media`
- `updateMetadata(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, array $data): Nvl\Media\Models\Media`
- `relocate(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, string $disk, Nvl\Media\Enums\MediaVisibility $visibility, ?int $expectedRevision = …): Nvl\Media\Models\Media`
- `delete(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, bool $force = …, ?Illuminate\Database\Eloquent\Model $owner = …): bool`
- `purgeDeletedFiles(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, ?Illuminate\Database\Eloquent\Model $owner = …): bool`
- `bulkDelete(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids): int`
- `move(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids, string $folder): int`
- `bulkTag(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids, array $tags): int`
- `tag(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, array $add = …, array $remove = …): Nvl\Media\Models\Media`

`purgeDeletedFiles()` retries physical deletion for a canonical soft-deleted asset. It requires current Delete authorization, no ambient transaction on the actual Media connection, and any supplied canonical owner on that same connection. It returns true only after the original and every retained variation object are confirmed absent; false permits retry after storage failure. The tombstone and variation history remain intact. Explicit purge is independent of `delete_files_on_media_delete`.

`updateMetadata()` accepts only `isPublic?: bool`, `tags?: list<string>|null`, `metadata?: array<string, mixed>|null`, `translations?: array<string, array{title?: string|null, alt?: string|null, caption?: string|null, description?: string|null}>|null`, and `translation_mode?: Nvl\Translatable\Enums\TranslationSyncMode`. The translation mode is a native enum; omitted means Patch. Unsupported fields fail. A supplied `isPublic` must equal the current visibility.

Visibility changes use another admitted disk:

```php
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Services\MediaLibrary;

$private = app(MediaLibrary::class)->relocate(
    actor: $actor,
    media: $asset,
    disk: 'private',
    visibility: MediaVisibility::Private,
    expectedRevision: $asset->revision,
);
```

Relocation rejects the same disk and a public-to-private change while the asset has multiple associations. It copies and verifies the original and variations, checks the revision, swaps canonical metadata, then removes source objects after the outer commit. Configure the destination's physical privacy and Private policy before calling it. It cannot recall previously published CDN copies.

## `MediaReader`

Namespace: `Nvl\Media\Services\MediaReader`.

- `paginate(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $user, array $filters = …, bool $includeVariations = …): Illuminate\Contracts\Pagination\LengthAwarePaginator`
- `filterOptions(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $user): array`
- `get(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $id, bool $includeVariations = …): Nvl\Media\Models\Media`
- `getMany(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids, bool $includeVariations = true): Illuminate\Database\Eloquent\Collection`
- `usages(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $id): Illuminate\Support\Collection`
- `findMany(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids): Illuminate\Database\Eloquent\Collection`
- `slots(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model $owner, array $slots, int $limit = …): array`

`getMany()` is the read-authorized bulk counterpart of `get()`. Pass a list of
UUIDs, bounded by the smaller of 100 and `nvl-media.query.maximum_page_size`.
An empty list returns an empty collection; repeated IDs are returned once in
first-input order. Missing, deleted, invisible or unauthorized items fail the
whole read. Translations, associations and optionally image variations are eager
loaded with a fixed number of media-table reads; every item still receives its
View decision. `findMany()` retains its separate mutation-oriented contract.

`paginate()` accepts `search`, `type` (`MediaType` or its backed string), `disk`, `isPublic`, `collection`, `tag`, `folder`, `mimeType`, `extension`, `locale`, `associableType`, `per_page`, `page`, `sortBy` and `sortDirection`. Sort fields are `created_at`, `updated_at`, `filename`, `size`, `type`, `extension` or `mime_type`; direction is `asc` or `desc`. Unknown fields fail. Pages are bounded by `nvl-media.query.maximum_page_size`.

Pagination requires List and View authorization for every returned row; a denied row fails the page rather than changing its totals. Filter metadata requires List. Finite slot reads admit the canonical owner, omit inactive associations and unavailable assets before applying limits, and evaluate owner-aware View authority.

## `MediaUrlResolver`

Namespace: `Nvl\Media\Services\MediaUrlResolver`.

- `forMedia(Media $media, string $variation = '', ?MediaDeliveryMode $mode = null): string`
- `forExistingMedia(?Media $media, string $variation = '', ?MediaDeliveryMode $mode = null): ?string`
- `forVariation(MediaImageVariation $variation, ?MediaDeliveryMode $mode = null): string`
- `buildUrl(Media $media, array $parameters = [], ?MediaDeliveryMode $mode = null): string`
- `publicUrl(Media $media, array $parameters = [], ?MediaDeliveryMode $mode = null): string`
- `signedUrl(Authenticatable|SystemActor $actor, Media $media, DateTimeInterface $expiration, array $parameters = []): string`
- `temporaryUrl(Authenticatable|SystemActor $actor, Media $media, DateTimeInterface $expiration, string $variation = ''): string`
- `path(Media $media, string $variation = ''): string`

`Media`, `MediaImageVariation`, `MediaDeliveryMode`, `Authenticatable` and `SystemActor` refer to their native package/framework types. Pure URL reads never mint a private bearer grant. With Application selected, private reads return the authenticated application route; consumers authorize each actual request. If a disk selects a bearer mode as its default, ordinary private URL reads fail unless the caller explicitly selects an allowed Application override. Public helpers reject private records. Explicit `MediaDeliveryMode` overrides must be permitted by the disk policy.

The two capability operations require an actor with Download authority, reload the canonical record and requested variation from the primary connection, and reject unavailable assets. `signedUrl()` emits a revision-bound signed application link; `temporaryUrl()` emits a provider URL without an unsigned fallback. Both require a future expiration within the disk's bound. Owned private assets require the scoped application route. An unavailable or filtered named variation never silently becomes an original-file grant. Application endpoints must validate signature, current revision, availability and canonical owner/scope; these are replayable bearer links until expiry.

```php
use Nvl\Media\Services\MediaUrlResolver;

$urls = app(MediaUrlResolver::class);
$authenticatedUrl = $urls->forMedia($private);
$signedBearerUrl = $urls->signedUrl($actor, $private, now()->addMinutes(2), ['v' => 'thumb']);
// For an unowned private asset on an admitted provider disk:
$providerBearerUrl = $urls->temporaryUrl($actor, $private, now()->addMinutes(2), 'thumb');
```

Each requested named variation must exist and be available at the current revision for capability issuance. Ordinary public/authenticated display URL helpers may fall back to the parent original for an unavailable variation; this display convenience is not a capability grant. Consumer byte-serving endpoints must reject an explicit unavailable variation.

`path()` resolves an absolute local path only on adapters supporting local paths; it is not an object-storage download mechanism or an authorization decision. Keep paths and storage identities server-side.

## `MediaAssetService`

Namespace: `Nvl\Media\Services\MediaAssetService`.

- `resolve(Media $media, ?string $variationLabel = null, MediaDeliveryMode $mode = MediaDeliveryMode::Application): array{disk: string, path: string, mime_type: string, etag: string}`

This read-only helper resolves server-side storage metadata for a consumer HTTP adapter. Authenticate and authorize the requester before calling it on an authenticated route; validate the scoped bearer signature, expiry and revision before calling it on a signed route. Pass Application for public/authenticated routes or SignedApplication for signed routes. It does not authenticate HTTP requests or grant a capability.

Resolution reloads canonical Media facts and variations from the primary connection, compares persisted storage/visibility/revision facts with the supplied model, checks current availability and delivery policy, and rejects missing or stale requested variations. Carried model attributes and preloaded relations cannot choose another object. Keep `disk` and `path` out of public responses; stream/proxy the resolved bytes and preserve the [HTTP delivery contract](http-api.md).

## `MediaDeliveryPolicy` and `MediaDeliveryMode`

`Nvl\Media\Services\MediaDeliveryPolicy` resolves and validates `nvl-media.delivery` without storage writes:

- `mode(string $disk, MediaVisibility $visibility, ?MediaDeliveryMode $override = null): MediaDeliveryMode`
- `assertVisibility(string $disk, MediaVisibility $visibility): void`
- `assertExpiration(string $disk, DateTimeInterface $expiration): void`
- `maximumTtl(string $disk): int`
- `validate(): void`

`Nvl\Media\Enums\MediaDeliveryMode` exposes `Direct`, `Application`, `SignedApplication` and `TemporaryStorage`. Public media supports Direct/Application; private media supports Application/SignedApplication/TemporaryStorage. Each visibility policy contains `mode` and an `allowed` list, or is `false` to disable that visibility. Physical credentials, roots and CDN base URLs remain Laravel filesystem configuration. See [delivery configuration](configuration.md#asset-delivery).

Application serving also checks the current visibility and relevant delivery mode when resolving bytes. Known URLs cannot bypass a disabled policy. Public CDN caches and provider bearer URLs retain their external revocation limits.

## `MediaMultipart`

Namespace: `Nvl\Media\Services\MediaMultipart`.

- `initiate(array $data, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array`
- `signPart(array $part, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array`
- `complete(array $completion, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Media\Models\Media`
- `abort(string $uploadId, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void`

Native request array shapes:

- Initiation: `array{disk: string, filename: string, mimeType: string, size: int, checksum: string, visibility?: MediaVisibility, folder?: string|null}`. Visibility defaults to Private. Size must be positive and within the configured maximum; checksum is a lowercase 64-character hexadecimal whole-object SHA-256.
- Part signing: `array{uploadId: string, partNumber: int, checksum: string, byteLength: int}`. The UUID is server-issued; part length must match the persisted fixed-size plan, and checksum is the lowercase hexadecimal SHA-256 of that part. Signing returns `partNumber`, `url`, required `headers`, and `expiresAt`.
- Completion: `array{uploadId: string, parts: list<array{partNumber: int, etag: string, checksum?: string|null}>}`. Supply every expected part once with its provider ETag; provider-specific returned checksum is optional. Persisted part facts, provider object identity, total size and digest are verified server-side.

These methods reject unknown fields. Native `MediaVisibility` values are required; consumers translate transport strings before invocation. Initiation's returned session includes server-only disk/object/uploader facts; project a reviewed client response. Completion produces `pending_scan`, not an available asset.

## `MediaVariations`

Namespace: `Nvl\Media\Services\MediaVariations`.

- `generate(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media $media, Nvl\Media\Conversions\ConversionDefinition $definition, ?int $expectedRevision = …): ?Nvl\Media\Models\MediaImageVariation`

## `MediaScan`

Namespace: `Nvl\Media\Services\MediaScan`.

- `finalize(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, array $result): Nvl\Media\Models\Media`

The scan result shape is `array{clean: bool, mimeType: string, extension: string, size: int, checksum: string, diagnostics?: array<string, scalar|null>}`. Size is a nonnegative native integer and checksum is lowercase hexadecimal SHA-256. Unknown keys or nested diagnostic values fail. A trusted scanner supplies exact verified-byte facts; the service compares them with the pending asset and applies structural SVG scanning where enabled. This is distinct from the synchronous `MediaContentScanner::scan(UploadedFile $file): void` contract, which rejects content by throwing.

## `MediaImport`

Namespace: `Nvl\Media\Services\MediaImport`.

- `adopt(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $sourceTable, ?string $translationTable = …, ?string $variationTable = …, ?string $uploaderType = …, string $defaultLocale = …, bool $apply = …): array`

## `MediaOwnerCatalog`

Namespace: `Nvl\Media\Ownership\MediaOwnerCatalog`.

- `import(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $grantId, int $expectedGrantRevision, int $expectedSourceRevision, string $idempotencyKey): Nvl\Media\Models\Media`
- `grant(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $sourceId, int $sourceRevision): Nvl\Media\Models\MediaOwnerGrant`
- `revoke(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $grantId, int $expectedRevision): Nvl\Media\Models\MediaOwnerGrant`
- `inspect(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $grantId): array`
- `stage(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $source): array`
- `persist(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $source, array $file, string $idempotencyKey): Nvl\Media\Models\Media`
- `discard(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $file): void`

Catalog import stages a private copy on the source disk. That disk must admit Private visibility; a public-only CDN disk cannot hold the imported private copy. Staging and persistence recheck policy, while discard remains available after a policy is disabled. No destination disk is selected implicitly.

## `HasNvlMedia`

Namespace: `Nvl\Media\Contracts\HasNvlMedia`.

- `nvlMediaAssociations(): Illuminate\Database\Eloquent\Relations\MorphMany`
- `registerNvlMedia(Nvl\Media\MediaRegistration $media, ?Nvl\Media\Models\Media $asset = …): void`

## `MediaRegistration`

Namespace: `Nvl\Media\MediaRegistration`.

- `forOwner(Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, ?Nvl\Media\Models\Media $asset = …): self`
- `slot(string $name): Nvl\Media\Slots\MediaSlot`
- `conversion(string $name): Nvl\Media\Conversions\ConversionDefinition`
- `getSlot(string $name): ?Nvl\Media\Slots\MediaSlot`
- `slots(): Illuminate\Support\Collection`
- `conversions(): array`

## `MediaSlot`

Namespace: `Nvl\Media\Slots\MediaSlot`.

- `useDisk(string $disk): static`
- `resolveDisk(?bool $isPublic = …): string`
- `path(string $template): static`
- `isPublic(bool $public = …): static`
- `publicReusable(): static`
- `privateExclusive(): static`
- `oneToOne(): static`
- `shared(): static`
- `exclusive(): static`
- `singleFile(): static`
- `onlyKeepLatest(int $max): static`
- `acceptsMimeTypes(array $mimeTypes): static`
- `acceptsFile(Closure $callback): static`
- `maxFileSize(int $bytes): static`
- `useFallbackUrl(string $url, string $conversion = …): static`
- `useFallbackPath(string $path, string $conversion = …): static`
- `withConversions(array $conversions): static`
- `addConversion(string $name, callable $config): static`
- `withTags(array $tags): static`
- `getFallbackUrl(string $conversion = …): string`
- `getFallbackPath(string $conversion = …): string`
- `isShared(): bool`
- `isExclusive(): bool`
- `isReusable(): bool`
- `isPrivate(): bool`
- `convertTo(Nvl\Media\Enums\MimeType|string $format): static`
- `withQuality(int $quality): static`
- `maxSize(int $pixels): static`
- `shouldConvertOriginal(): bool`
- `getConversionDefinitions(): array`

`convertTo()`, `withQuality()` and `maxSize()` re-encode the original image inside `MediaUploader::upload()`, after the submitted file has passed the slot's MIME, size and acceptor rules. Non-images and the formats in `nvl-media.optimization.skip_formats` (SVG, GIF) are stored unchanged, a failed conversion stores the submitted file unless it exceeds `maxSize()`, and `MediaAttachments::copySlot()` always keeps the source bytes. `withTags()` adds its tags to every upload into the slot. `useFallbackUrl()` and `useFallbackPath()` only declare values: the package has no owner-slot URL method, so the host reads them with `getFallbackUrl()` / `getFallbackPath()` when it projects an empty slot.

## `ConversionDefinition`

Namespace: `Nvl\Media\Conversions\ConversionDefinition`.

- `fromPreset(string $name, array $preset): self`
- `fromPayload(string $name, array $payload): self`
- `resize(string $name, ?int $width = …, ?int $height = …, int $quality = …, ?string $format = …): self`
- `cropTo(string $name, int $width, int $height, string $position = …, int $quality = …, ?string $format = …): self`
- `fitTo(string $name, string $method, int $width, int $height, int $quality = …, ?string $format = …): self`
- `formatOnly(string $name, string $format, int $quality = …): self`
- `width(?int $width): static`
- `height(int $height): static`
- `crop(int $width, int $height, string $position = …): static`
- `fit(Nvl\Media\Enums\ImageFit|string $method, int $width, int $height): static`
- `quality(int $quality): static`
- `format(Nvl\Media\Enums\ImageFormat|string $format): static`
- `compression(Nvl\Media\Enums\ImageCompression|string $compression): static`
- `sharpen(int $amount): static`
- `blur(int $amount): static`
- `greyscale(): static`
- `orientation(int $degrees): static`
- `flip(string $direction): static`
- `watermark(string $path, string $position = …, int $opacity = …): static`
- `background(string $color): static`
- `brightness(int $amount): static`
- `contrast(int $amount): static`
- `keepOriginalImageFormat(): static`
- `performOnSlots(string ...$slots): static`
- `queued(): static`
- `nonQueued(): static`
- `onQueue(?string $queueName = …): static`
- `enabled(bool $enabled = …): static`
- `disabled(): static`
- `validate(): void`
- `toPayload(): array`
- `shouldBePerformedOn(string $slot): bool`
- `getResultExtension(string $originalExtension): string`
- `getResultMimeType(string $originalMimeType): string`

## `Media`

Namespace: `Nvl\Media\Models\Media`.

The native model supplies persistence hooks, extension points and in-memory reads. Its methods do not replace the actor-authorized services: use those services for consumer reads and mutations rather than writing storage fields or querying records to bypass authorization.

- `allowsCentralCatalog(): bool`
- `resolveRouteBinding($value, $field = …): ?Nvl\Media\Models\Media`
- `setIsPublicAttribute(bool $value): void`
- `setVisibilityAttribute(Nvl\Media\Enums\MediaVisibility|string $visibility): void`
- `newEloquentBuilder($query): Nvl\Media\Builders\MediaBuilder`
- `associations(): Illuminate\Database\Eloquent\Relations\HasMany`
- `imageVariations(): Illuminate\Database\Eloquent\Relations\HasMany`
- `uploader(): Illuminate\Database\Eloquent\Relations\MorphTo`
- `scopePublic(Illuminate\Database\Eloquent\Builder $query): void`
- `scopePrivate(Illuminate\Database\Eloquent\Builder $query): void`
- `scopeAvailable(Illuminate\Database\Eloquent\Builder $query): void`
- `isAvailable(): bool`
- `scopeOfType(Illuminate\Database\Eloquent\Builder $query, Nvl\Media\Enums\MediaType $type): void`
- `scopeOnDisk(Illuminate\Database\Eloquent\Builder $query, string $disk): void`
- `scopeWithTag(Illuminate\Database\Eloquent\Builder $query, string $tag): void`
- `getUrl(string $variation = …, ?Nvl\Media\Enums\MediaDeliveryMode $mode = null): string`
- `getPath(string $variation = …): string`
- `getTemporaryUrl(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, DateTimeInterface $expiration, string $variation = …): string`
- `buildUrl(array $parameters = …, ?Nvl\Media\Enums\MediaDeliveryMode $mode = null): string`
- `buildPublicUrl(array $parameters = …, ?Nvl\Media\Enums\MediaDeliveryMode $mode = null): string`
- `buildPrivateUrl(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, DateTimeInterface $expiration, array $parameters = …): string`
- `getVariation(string $label): ?Nvl\Media\Models\MediaImageVariation`
- `hasVariation(string $label): bool`
- `getVariationUrl(string $label): string`
- `getVariationPath(string $label): string`
- `isUsed(): bool`
- `getUsagesSummary(): Illuminate\Support\Collection`
- `hasTag(string $tag): bool`
- `isImage(): bool`
- `isVideo(): bool`
- `isDocument(): bool`
- `isAudio(): bool`
- `isArchive(): bool`
- `humanReadableSize(): string`
- `fileExistsOnDisk(): bool`
- `buildPath(): string`
- `rootFolder(?string $disk = …): string`
- `storagePath(string $folder, ?string $disk = …): string`
- `getTable(): string`
- `getConnectionName(): ?string`
- `save(array $options = …): bool`
- `delete(): ?bool`
- `forceDelete()`
- `forceDestroy($ids)`
- `newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder`
- `fresh($with = …): ?static`
- `refresh(): static`
- `refreshForUpdate(): static`
- `getKeyType()`
- `getIncrementing()`
- `resolveRouteBindingQuery($query, $value, $field = …)`
- `getRelationValue($key): mixed`
- `newUniqueId()`
- `uniqueIds()`
- `factory($count = …, $state = …)`
- `bootHasOwnership(): void`
- `ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition`
- `initializeHasUniqueStringIds()`
- `bootSoftDeletes()`
- `initializeSoftDeletes()`
- `forceDeleteQuietly()`
- `restore()`
- `restoreQuietly()`
- `trashed()`
- `softDeleted($callback)`
- `restoring($callback)`
- `restored($callback)`
- `forceDeleting($callback)`
- `forceDeleted($callback)`
- `isForceDeleting()`
- `getDeletedAtColumn()`
- `getQualifiedDeletedAtColumn()`
- `translationDefinition(): Nvl\Translatable\RelatedTranslationDefinition`
- `translations(): Illuminate\Database\Eloquent\Relations\HasMany`
- `translation(?string $locale = …): Illuminate\Database\Eloquent\Relations\HasOne`
- `getTranslation(?string $locale = …, bool $withFallback = …): ?Illuminate\Database\Eloquent\Model`
- `getCurrentLocale(): string`
- `setLocale(string $locale): self`
- `isTranslatableAttribute(string $key): bool`
- `resolveTranslation(string $field, ?string $locale = …): Nvl\Translatable\TranslationResolution`
- `translated(string $field, ?string $locale = …): mixed`
- `getTranslatedAttributes(?string $locale = …): array`
- `getAllTranslations(): Illuminate\Database\Eloquent\Collection`
- `translationResourceKey(): string|int`
- `scopeWithResolvedTranslations(Illuminate\Database\Eloquent\Builder $query, ?string $locale = …): Illuminate\Database\Eloquent\Builder`
- `scopeWithAllTranslations(Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder`
- `scopeWhereTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, mixed $operator, mixed $value = …, ?string $locale = …): Illuminate\Database\Eloquent\Builder`
- `scopeWhereTranslationNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale = …): Illuminate\Database\Eloquent\Builder`
- `scopeWhereTranslationNotNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale = …): Illuminate\Database\Eloquent\Builder`
- `scopeOrderByTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, string $direction = …, ?string $locale = …): Illuminate\Database\Eloquent\Builder`
- `hasTranslation(?string $locale = …): bool`
- `getAvailableLocales(): array`

## Storage and operation guarantees

Ingestion checks actual bytes, detected MIME, size, scanner status and slot policy before making an asset available. Private and exclusive slots, deduplication, mutation locks, multipart replay and file cleanup stay in the services. Use the actual configured Media connection and native transaction management. New staged objects are deleted after rollback of their originating savepoint or outer transaction. Superseded/source objects are deleted, and queued variation work is scheduled, only after the outer commit; work from a rolled-back savepoint is cancelled even if the outer transaction later commits.

Storage is external to the database transaction. Cleanup callbacks are best effort, log failures and are not a durable outbox or distributed transaction; process exit can leave orphaned objects or undelivered work. Reconciliation, provider backups and recovery procedures remain necessary. A separate owner-slot ledger connection is a saga boundary, not cross-database atomicity. Source files, scanners and adapters retain their operational limits.

Metadata writes may retain `isPublic` but cannot change it. Visibility changes use verified cross-disk relocation; shared public assets cannot become private. Uploads, replacements, moves, multipart part-signing/completion and variation generation revalidate the disk's current visibility policy before new file/provider writes. Abort remains available for multipart cleanup after a policy is disabled. Multipart scan finalization checks structural SVG safety against the verified stored bytes as well as malware attestation.

Native Ownership is optional. Unowned hosts do not require its package. Declared/adopted storage requires a compatible `nvl/ownership:^7.0` runtime and explicit resource/context admission, and fails closed without that runtime. A configured model and its relations preserve owner scoping; application actor visibility still belongs in the authorized reader/service entry point. Raw model reads do not replace host authorization. Catalog grants and copies use `MediaOwnerCatalog`, with explicit native owner admission and reviewed source revisions.

See [configuration](configuration.md), [images and queues](images-and-queues.md), [S3](s3.md), [extension contracts](extending.md) and [consumer HTTP integration](http-api.md). There are no package HTTP routes, controller bindings, fluent workflow facades, Spatie Data contracts or frontend generators.
