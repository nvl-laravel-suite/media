# Native PHP API

Use PHP 8.4 and Laravel 13. Consumers own requests, controllers, Actions, DTOs, JSON resources and frontend types. Package services own validation, explicit actor authorization, canonical owner admission, transactions, locking and recovery. Native models, enums, files, domain slots and documented arrays replace the old DTO and Action interfaces.

`SystemActor::Background` is an identity and needs an explicit policy grant. Human actors are persisted `Authenticatable` models; the service checks their current identity. Bind the package authorization contracts before user-driven operations.

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
- `bulkDelete(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids): int`
- `move(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids, string $folder): int`
- `bulkTag(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids, array $tags): int`
- `tag(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, array $add = …, array $remove = …): Nvl\Media\Models\Media`

## `MediaReader`

Namespace: `Nvl\Media\Services\MediaReader`.

- `paginate(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $user, array $filters = …, bool $includeVariations = …): Illuminate\Contracts\Pagination\LengthAwarePaginator`
- `filterOptions(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $user): array`
- `get(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $id, bool $includeVariations = …): Nvl\Media\Models\Media`
- `usages(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $id): Illuminate\Support\Collection`
- `findMany(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids): Illuminate\Database\Eloquent\Collection`
- `slots(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model $owner, array $slots, int $limit = …): array`

## `MediaMultipart`

Namespace: `Nvl\Media\Services\MediaMultipart`.

- `initiate(array $data, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array`
- `signPart(array $part, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array`
- `complete(array $completion, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Media\Models\Media`
- `abort(string $uploadId, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void`

## `MediaVariations`

Namespace: `Nvl\Media\Services\MediaVariations`.

- `generate(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media $media, Nvl\Media\Conversions\ConversionDefinition $definition, ?int $expectedRevision = …): ?Nvl\Media\Models\MediaImageVariation`

## `MediaScan`

Namespace: `Nvl\Media\Services\MediaScan`.

- `finalize(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, array $result): Nvl\Media\Models\Media`

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
- `performOnSlots(string ...$slots = …): static`
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
- `getUrl(string $variation = …): string`
- `getPath(string $variation = …): string`
- `getTemporaryUrl(DateTimeInterface $expiration, string $variation = …): string`
- `buildUrl(array $parameters = …, ?DateTimeInterface $expiration = …, ?string $owner = …): string`
- `buildPublicUrl(array $parameters = …): string`
- `buildPrivateUrl(array $parameters = …, ?DateTimeInterface $expiration = …, ?string $owner = …): string`
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
- `translationOptions(): Nvl\Translatable\TranslatableOptions`
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

Ingestion checks actual bytes, detected MIME, size, scanner status and slot policy before making an asset available. Private and exclusive slots, deduplication, mutation locks, multipart replay and file cleanup stay in the services. Use the actual model connection; side effects respect its enclosing commit or rollback. Source files, scanners and object-storage adapters retain their documented operational limits.

Native Ownership is optional. Declared/adopted storage fails closed without its runtime. A configured model and its relations preserve owner scoping; application actor visibility still belongs in the authorized reader/service entry point. Raw model reads do not replace host authorization. Catalog grants and copies use `MediaOwnerCatalog`, with explicit native owner admission and reviewed source revisions.

See [configuration](configuration.md), [images and queues](images-and-queues.md), [S3](s3.md), [extension contracts](extending.md) and [consumer HTTP integration](http-api.md). There are no package HTTP routes, controller bindings, fluent workflow facades, Spatie Data contracts or frontend generators.
