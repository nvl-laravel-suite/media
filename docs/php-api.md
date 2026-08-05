# PHP API reference

This reference documents the supported PHP integration surface of NVL Media 1.x. The shortest path is the `HasMedia` contract plus `InteractsWithMedia`; application services may use `MediaLibraryContract` or the `Nvl\Media\Facades\Media` facade; advanced integrations may inject a focused Action or contract.

## Choosing an entry point

| Entry point | Best for | Authorization boundary |
| --- | --- | --- |
| `InteractsWithMedia` | Model-centric upload, retrieval, and lifecycle calls | Authorize in the calling controller, job, or service |
| `Media` facade | Concise application orchestration | Authorize before mutations with a policy or `Media::allows()` |
| `MediaLibraryContract` | Constructor injection and boundary replacement | Same behavior as the facade |
| Focused contracts and Actions | Advanced integration or replacing one operation | Caller-owned |
| Management HTTP API | Authenticated library interfaces | Package policy and configured middleware |

Facade and direct PHP mutation calls are trusted application-service calls. They enforce ingestion, scanning, storage, integrity, locking, and transaction policy, but they do not infer an ambient actor for authorization.

## Media-enabled model

Implement `Nvl\Media\Contracts\HasMedia` and use `Nvl\Media\Traits\InteractsWithMedia`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\ImageFit;
use Nvl\Media\Enums\ImageFormat;
use Nvl\Media\Traits\InteractsWithMedia;

final class Article extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaSlots(): void
    {
        $this->addMediaSlot('cover')
            ->oneToOne()
            ->useDisk('s3')
            ->path('articles/{model_id}/cover')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxFileSize(8 * 1024 * 1024)
            ->addConversion(
                'card',
                static fn (ConversionDefinition $conversion): ConversionDefinition => $conversion
                    ->fit(ImageFit::Crop, 640, 360)
                    ->format(ImageFormat::Webp)
                    ->quality(82),
            );

        $this->addMediaSlot('gallery')
            ->publicReusable()
            ->useDisk('s3')
            ->onlyKeepLatest(20);

        $this->addMediaSlot('contract')
            ->oneToOne()
            ->acceptsMimeTypes(['application/pdf']);
    }
}
```

Slots are registered lazily per model instance. Slot names describe the role of an asset; association collections describe where an attachment is grouped. They default to the same name but may be separated with `MediaAdder::toCollection()`.

## Upload sources

Every source converges on the same authoritative ingestion pipeline.

| Model call | Facade call | Source behavior |
| --- | --- | --- |
| `$owner->addMedia($file, $slot)` | `Media::add($owner, $file, $slot)` | `UploadedFile` or explicit local path |
| `$owner->copyMedia($file)` | `Media::copy($owner, $file)` | Retains an explicit local source |
| `$owner->addMediaFromRequest('file')` | `Media::fromRequest($owner, 'file')` | Resolves a request upload; package never deletes the request source |
| `$owner->addMediaFromUrl($url, ...$mimes)` | `Media::fromUrl($owner, $url, ...$mimes)` | Opt-in, bounded, DNS-pinned remote download |
| `$owner->addMediaFromBase64($payload, ...$mimes)` | `Media::fromBase64($owner, $payload, ...$mimes)` | Bounded streaming decode; data URI and raw base64 supported by the resolver |
| `$owner->addMediaFromString($contents)` | `Media::fromString($owner, $contents)` | Materializes bounded text content |
| `$owner->addMediaFromDisk($key, $disk)` | `Media::fromDisk($owner, $key, $disk)` | Streams an existing source object from a Laravel disk |

`addMedia($explicitLocalPath)` owns the explicit local source and deletes it only after the real root database transaction commits. Use `copyMedia()` or `preservingOriginal()` when the caller must retain it. Failure and rollback retain caller-owned sources.

All source methods return `MediaAdder`. Call a terminal method to execute the upload:

```php
$media = $article
    ->addMedia($request->file('cover'), 'cover')
    ->usingFileName('Summer campaign.jpg')
    ->withTags(['campaign', 'summer'])
    ->withCustomProperties(['source' => 'editor'])
    ->withAssociationMeta(['placement' => 'hero'])
    ->toLocale('en')
    ->withoutVariations()
    ->upload();
```

## `MediaAdder`

All non-terminal methods return the same builder.

| Method | Contract |
| --- | --- |
| `usingSlot(string $slot)` | Select the slot used by `upload()` or `slot()` |
| `preservingOriginal(bool $preserve = true)` | Retain an explicit caller-owned local source after commit |
| `usingFileName(string $fileName)` | Set the display filename claim; storage identity remains random |
| `sanitizingFileName(Closure $sanitizer)` | Apply a consumer sanitizer to the display filename before package sanitization |
| `withCustomProperties(array $properties)` | Merge canonical file-level metadata |
| `withTags(array $tags)` | Append canonical file-level tags |
| `tags(string ...$tags)` | Variadic form of `withTags()` |
| `toLocale(string $locale)` | Set the locale stored on the association |
| `withOrder(int $order)` | Set the association order |
| `toDisk(string $disk)` | Override the slot disk; the disk must remain allowlisted |
| `toFolder(string $folder)` | Override the resolved folder; path safety is still enforced |
| `asPublic(bool $public = true)` | Override slot visibility for this upload |
| `withVariations(array $variations)` | Add or override named conversion definitions for this upload |
| `withoutVariations()` | Suppress all automatic variation dispatch for this terminal upload |
| `withAssociationMeta(array $meta)` | Merge placement-specific association metadata |
| `allowingDuplicates(bool $allow = true)` | Disable digest reuse for this upload |
| `uploadedBy(Model&Authenticatable $uploader)` | Attribute an upload explicitly when no authenticated request is available, such as in a queued job |
| `toCollection(string $collection)` | Store the association under a collection different from the slot |
| `slot(?string $name = null, ?string $disk = null)` | Upload and attach; slot precedence is argument, `usingSlot()`, then `default` |
| `slotOnCloudDisk(?string $name = null)` | Upload on `filesystems.cloud`, falling back to `s3` |
| `upload()` | Alias for `slot()` |

Ordinary uploads attribute the authenticated Eloquent actor when one is available. Jobs and console commands have no request guard, so call `uploadedBy($actor)` before the terminal method when private ownership or uploader-scoped deduplication matters.

`withVariations()` accepts `array<string, ConversionDefinition|array<string,mixed>>`. Definitions are normalized, validated, and persisted. Resolution precedence is upload override, model, slot, then global configuration. On shared digest reuse, identical definitions are idempotent, new labels may be added, and conflicting definitions for an existing label fail.

## Facade and injectable library

Import the package facade explicitly:

```php
use Nvl\Media\Facades\Media;
```

The package intentionally registers no global `Media` alias. The facade resolves `MediaLibraryContract`, so constructor injection and facade calls use the same implementation.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Article;
use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Models\Media;

final readonly class ArticleMedia
{
    public function __construct(
        private MediaLibraryContract $media,
    ) {}

    public function uploadCover(Article $article, UploadedFile $file): Media
    {
        return $this->media
            ->add($article, $file, 'cover')
            ->upload();
    }
}
```

### `MediaLibraryContract` methods

| Facade call | Return | Behavior |
| --- | --- | --- |
| `Media::add($owner, $file, ?$slot)` | `MediaAdder` | Create an owned-source upload builder |
| `Media::copy($owner, $file)` | `MediaAdder` | Create a source-preserving upload builder |
| `Media::fromRequest($owner, $key)` | `MediaAdder` | Resolve a request upload |
| `Media::fromUrl($owner, $url, ...$allowedMimeTypes)` | `MediaAdder` | Resolve an enabled remote source |
| `Media::fromBase64($owner, $payload, ...$allowedMimeTypes)` | `MediaAdder` | Resolve a bounded encoded source |
| `Media::fromString($owner, $contents)` | `MediaAdder` | Resolve string contents |
| `Media::fromDisk($owner, $key, ?$disk)` | `MediaAdder` | Resolve a disk object |
| `Media::paginate($filters = [], ?$actor = null, $includeVariations = false)` | `LengthAwarePaginator<Media>` | Filter and visibility-scope the library; page size is capped by configuration |
| `Media::findOrFail($id, $includeVariations = true)` | `Media` | Find a live media row and eager-load display relations |
| `Media::usages($id)` | `Collection<MediaUsage>` | Return association usages |
| `Media::attach($media, $owner, $collection = 'default', ?$locale = null, ?$order = null, $metadata = [], $dispatchVariations = true)` | `MediaAssociation` | Idempotently attach available media |
| `Media::reuse($media, $owner, $collection = 'default', ?$locale = null, ?$order = null, $metadata = [], $dispatchVariations = true)` | `Media` | Attach an existing public reusable asset and return it |
| `Media::detach($media, $owner, ?$collection = null)` | `int` | Remove matching associations and return the count |
| `Media::delete($media, $force = false)` | `bool` | Soft-delete the record and schedule physical cleanup after commit |
| `Media::replace($media, $file)` | `Media` | Replace the source through stage–verify–swap semantics |
| `Media::rename($media, $filename)` | `Media` | Change the display filename only |
| `Media::updateMetadata($media, $data)` | `Media` | Apply `UpdateMediaPayload` atomically |
| `Media::generateVariation($media, $definition, ?$expectedRevision = null)` | `?MediaImageVariation` | Generate or replace one named variation; stale work returns `null` |
| `Media::finalizeScan($media, $result)` | `Media` | Finalize a `pending_scan` object using scanner attestation |
| `Media::initiateMultipart($data, $actor)` | `MultipartUploadSessionData` | Create a persisted direct-upload session |
| `Media::signMultipartPart($part, $actor)` | `SignedMultipartPartData` | Sign one declared part for its owning actor |
| `Media::completeMultipart($completion, $actor)` | `Media` | Idempotently complete and verify a persisted session |
| `Media::abortMultipart($uploadId, $actor)` | `void` | Idempotently abort an unfinished session |
| `Media::allows($actor, $ability, ?$media = null, ?$owner = null)` | `bool` | Evaluate the same authorization bridge used by policies and queries |

`Media::delete($media, force: true)` is an intentional administrative global delete. Authorization does not bypass the shared-asset integrity guard automatically; the caller must both authorize the actor and opt into force.

### Filtering

`Media::paginate()` accepts a validated `MediaFilter` or an array:

```php
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Facades\Media;

$page = Media::paginate(
    new MediaFilter(
        search: 'campaign',
        type: MediaType::IMAGE,
        disk: 's3',
        isPublic: true,
        collection: 'gallery',
        tag: 'summer',
        folder: 'articles',
        mimeType: 'image/webp',
        extension: 'webp',
        locale: 'en',
        associableType: Article::class,
        perPage: 50,
        page: 1,
        sortBy: 'created_at',
        sortDirection: 'desc',
    ),
    actor: $request->user(),
    includeVariations: true,
);
```

Allowed sort columns are `created_at`, `updated_at`, `filename`, `size`, `type`, `extension`, and `mime_type`. Sort direction is `asc` or `desc`; `perPage` is capped at `media.query.maximum_page_size`, never above 100 in the shipped configuration.

### Metadata mutation

```php
use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Facades\Media;
use Nvl\Translatable\Enums\TranslationSyncMode;

$updated = Media::updateMetadata(
    $media,
    UpdateMediaPayload::validateAndCreate([
        'is_public' => true,
        'tags' => ['campaign', 'approved'],
        'metadata' => ['credit' => 'Studio'],
        'translations' => [
            'en' => [
                'title' => 'Summer campaign',
                'alt' => 'Model wearing the summer collection',
                'caption' => null,
                'description' => null,
            ],
        ],
        'translationMode' => TranslationSyncMode::Patch->value,
    ]),
);
```

`Patch` preserves omitted locale rows and fields. `Replace` removes omitted locale rows. Technical fields such as disk, folder, digest, MIME, extension, and storage hash are not localized and cannot be mutated through this DTO.

## Model trait reference

### Registration and relationships

| Method | Contract |
| --- | --- |
| `bootInteractsWithMedia()` | Registers owner deletion behavior; invoked by Eloquent |
| `media()` | Ordered `MorphToMany<Media>` relationship with collection, locale, order, and metadata pivot fields |
| `mediaAssociations()` | Direct `MorphMany<MediaAssociation>` relationship |
| `registerMediaSlots()` | Consumer hook for slot definitions |
| `registerMediaConversions(?Media $media = null)` | Consumer hook for model-level conversions |
| `addMediaSlot(string $name)` | Register and return a `MediaSlot` |
| `addMediaConversion(string $name)` | Register and return a model-level `ConversionDefinition` |
| `getModelConversions()` | Return registered model conversions keyed by label |
| `getMediaSlot(string $name = 'default')` | Return a registered slot or `null` |
| `getRegisteredMediaSlots()` | Return every registered slot keyed by name |
| `getMediaModel()` | Return the package `Media` model class |

### Sources and reuse

| Method | Return |
| --- | --- |
| `addMedia(string|UploadedFile $file, ?string $slot = null)` | `MediaAdder` |
| `copyMedia(string|UploadedFile $file)` | `MediaAdder` |
| `addMediaFromRequest(string $key)` | `MediaAdder` |
| `addMediaFromUrl(string $url, string ...$allowedMimeTypes)` | `MediaAdder` |
| `addMediaFromBase64(string $base64data, string ...$allowedMimeTypes)` | `MediaAdder` |
| `addMediaFromString(string $text)` | `MediaAdder` |
| `addMediaFromDisk(string $key, ?string $disk = null)` | `MediaAdder` |
| `reusePublicMedia(Media|string $media, string $collection = 'default', ?string $locale = null, ?int $order = null, array $metadata = [], bool $dispatchVariations = true)` | `Media` |

### Retrieval and delivery

| Method | Behavior |
| --- | --- |
| `getMedia(string $collection = 'default', array|callable $filters = [])` | Return ordered media; uses a loaded relationship when available |
| `getFirstMedia(...)` | Return the first matching item or `null` |
| `getLastMedia(...)` | Return the last matching item or `null` |
| `hasMedia(...)` | Check whether a matching item exists |
| `getFirstMediaUrl(string $collection = 'default', string $conversion = '')` | URL or configured fallback |
| `getLastMediaUrl(string $collection = 'default', string $conversion = '')` | URL or configured fallback |
| `getFirstMediaPath(string $collection = 'default', string $conversion = '')` | Path or configured fallback |
| `getLastMediaPath(string $collection = 'default', string $conversion = '')` | Path or configured fallback |
| `buildUrl(string $collection = 'default', array $parameters = [], ?DateTimeInterface $expiration = null)` | Centralized public/private URL for the first item |
| `buildPublicUrl(string $collection = 'default', array $parameters = [])` | Public route URL for the first item |
| `getFirstTemporaryUrl(DateTimeInterface $expiration, string $collection = 'default', string $conversion = '')` | Temporary URL or empty string |
| `getFallbackMediaUrl(string $collection = 'default', string $conversion = '')` | Slot fallback URL or empty string |
| `getFallbackMediaPath(string $collection = 'default', string $conversion = '')` | Slot fallback path or empty string |

Array retrieval filters compare canonical model attributes strictly. The special `tags => 'name'` filter checks tag membership. A callable receives each `Media` instance. For repeated access, eager-load `media` to avoid N+1 queries.

### Lifecycle and ordering

| Method | Behavior |
| --- | --- |
| `clearMediaCollection(string $collection = 'default')` | Detach/delete every item according to sharing rules |
| `clearMediaCollectionExcept(string $collection = 'default', array|Collection $except = [])` | Clear all except the supplied media models or UUIDs |
| `deleteMedia(string|Media $media)` | Globally delete one media record through the lifecycle service |
| `deleteAllMedia()` | Delete all media owned by this model |
| `detachMedia(string|Media $media, ?string $collection = null)` | Remove only this owner’s matching association |
| `deletePreservingMedia()` | Delete the owner without invoking package media cleanup for that delete |
| `updateMediaOrder(array $ordered_ids, string $collection = 'default')` | Set zero-based association order for supplied UUIDs |

Soft-deleting an owner preserves associations for restoration. Force deletion follows `media.delete_media_on_model_delete` unless `deletePreservingMedia()` is used deliberately.

## `MediaSlot`

| Method | Behavior |
| --- | --- |
| `useDisk(string $disk)` | Select a configured and allowlisted disk |
| `path(string $template)` | Set a folder template |
| `isPublic(bool $public = true)` | Set visibility |
| `publicReusable()` | Public + shared |
| `privateExclusive()` | Private + exclusive |
| `oneToOne()` | Private + exclusive + single file |
| `shared()` | Enable digest reuse |
| `exclusive()` | Require a new media row |
| `singleFile()` | Replace the previous slot item safely |
| `onlyKeepLatest(int $max)` | Keep at most `max` items, minimum one |
| `acceptsMimeTypes(array $mimeTypes)` | Restrict detected MIME types |
| `acceptsFile(Closure $callback)` | Add a synchronous application acceptance predicate |
| `maxFileSize(int $bytes)` | Add a slot byte limit |
| `useFallbackUrl(string $url, string $conversion = '')` | Register a URL fallback |
| `useFallbackPath(string $path, string $conversion = '')` | Register a path fallback |
| `withConversions(array $conversions)` | Register multiple named definitions |
| `addConversion(string $name, callable $config)` | Configure one named definition |
| `withTags(array $tags)` | Add default upload tags |
| `getFallbackUrl(string $conversion = '')` | Resolve a conversion-specific or default fallback |
| `getFallbackPath(string $conversion = '')` | Resolve a conversion-specific or default fallback |
| `isShared()` | Test sharing mode |
| `isExclusive()` | Test exclusive mode |
| `isReusable()` | Test public + shared |
| `isPrivate()` | Test private visibility |
| `convertTo(MimeType|string $format)` | Convert the stored original during adder optimization |
| `withQuality(int $quality)` | Set original conversion quality |
| `maxSize(int $pixels)` | Bound the original’s longest edge |
| `shouldConvertOriginal()` | Test whether original conversion is configured |
| `getConversionDefinitions()` | Return named slot conversions |

Path templates support `{model_type}`, `{model_id}`, `{id}`, `{collection}`, `{slug}`, `{date}`, `{year}`, `{month}`, `{day}`, and model attributes. The resolved folder remains constrained beneath `media.root_folder`.

## `ConversionDefinition`

Named variations are the only delivery-time transformation interface.

| Method | Behavior |
| --- | --- |
| `fromPreset(string $name, array $preset)` | Build and normalize a definition from configuration |
| `fromPayload(string $name, array $payload)` | Rehydrate a persisted versioned definition |
| `resize(string $name, ?int $width = null, ?int $height = null, int $quality = 85, ?string $format = null)` | Create a proportional resize definition |
| `cropTo(string $name, int $width, int $height, string $position = 'center', int $quality = 85, ?string $format = null)` | Create an exact crop definition |
| `fitTo(string $name, string $method, int $width, int $height, int $quality = 85, ?string $format = null)` | Create a fitted resize definition |
| `formatOnly(string $name, string $format, int $quality = 85)` | Create a re-encode-only definition |
| `width(?int $width)` | Set output width |
| `height(int $height)` | Set output height |
| `crop(int $width, int $height, string $position = 'center')` | Exact crop |
| `fit(ImageFit|string $method, int $width, int $height)` | Apply crop, contain, stretch, or max fit |
| `quality(int $quality)` | Set encoder quality |
| `format(ImageFormat|string $format)` | Set output format |
| `compression(ImageCompression|string $compression)` | Set lossy/lossless mode |
| `sharpen(int $amount)` | Apply sharpening |
| `blur(int $amount)` | Apply blur |
| `greyscale()` | Convert to grayscale |
| `orientation(int $degrees)` | Rotate output |
| `flip(string $direction)` | Flip output |
| `watermark(string $path, string $position = 'bottom-right', int $opacity = 50)` | Apply a watermark |
| `background(string $color)` | Set background color |
| `brightness(int $amount)` | Adjust brightness |
| `contrast(int $amount)` | Adjust contrast |
| `keepOriginalImageFormat()` | Preserve the validated source format |
| `performOnSlots(string ...$slots)` | Limit the definition to slot names |
| `queued()` | Queue generation |
| `nonQueued()` | Generate synchronously after commit |
| `onQueue(?string $queueName = null)` | Set queued mode and optional queue |
| `enabled(bool $enabled = true)` | Enable the definition |
| `disabled()` | Disable the definition |
| `validate()` | Validate the complete definition and throw on invalid combinations |
| `toPayload()` | Return the stable persisted definition payload |
| `shouldBePerformedOn(string $slot)` | Test slot applicability |
| `getResultExtension(string $originalExtension)` | Resolve output extension |
| `getResultMimeType(string $originalMimeType)` | Resolve output MIME |

```php
$definition = (new ConversionDefinition('hero'))
    ->fit(ImageFit::Crop, 1600, 900)
    ->format(ImageFormat::Webp)
    ->compression(ImageCompression::Lossy)
    ->quality(82)
    ->performOnSlots('cover', 'gallery')
    ->onQueue('media');
```

## `Media` model

Prefer Actions for mutations. The model intentionally exposes relationships, scopes, and lightweight derived helpers.

### Relationships and scopes

| Method | Behavior |
| --- | --- |
| `associations()` | Association rows |
| `imageVariations()` | Generated variations |
| `uploader()` | Polymorphic uploader |
| `public()` / `scopePublic()` | Public visibility |
| `private()` / `scopePrivate()` | Private visibility |
| `available()` / `scopeAvailable()` | Available or processing-variations rows |
| `ofType(MediaType $type)` / `scopeOfType()` | Filter by type |
| `onDisk(string $disk)` / `scopeOnDisk()` | Filter by disk |
| `withTag(string $tag)` / `scopeWithTag()` | JSON tag membership |

### Helpers

| Method | Behavior |
| --- | --- |
| `isAvailable()` | Whether association and delivery are allowed |
| `getUrl(string $variation = '')` | Public or temporary URL |
| `getPath(string $variation = '')` | Absolute local path when supported by the disk |
| `getTemporaryUrl(DateTimeInterface $expiration, string $variation = '')` | Temporary URL |
| `buildUrl(array $parameters = [], ?DateTimeInterface $expiration = null, ?string $owner = null)` | Centralized visibility-aware URL |
| `buildPublicUrl(array $parameters = [])` | Public asset route URL |
| `buildPrivateUrl(array $parameters = [], ?DateTimeInterface $expiration = null, ?string $owner = null)` | Signed private asset route URL |
| `getVariation(string $label)` | Loaded variation model or `null` |
| `hasVariation(string $label)` | Test a loaded variation label |
| `getVariationUrl(string $label)` | Variation URL, falling back according to delivery behavior |
| `getVariationPath(string $label)` | Variation path |
| `isUsed()` | Whether any association exists |
| `getUsagesSummary()` | Generic owner type/id/collection summaries |
| `hasTag(string $tag)` | Strict tag membership |
| `isImage()` | Image type predicate |
| `isVideo()` | Video type predicate |
| `isDocument()` | Document type predicate |
| `isAudio()` | Audio type predicate |
| `isArchive()` | Archive type predicate |
| `humanReadableSize()` | Human-readable byte size |
| `fileExistsOnDisk()` | Cached physical existence check |
| `buildPath()` | Relative original object path |
| `rootFolder()` | Configured root prefix |
| `storagePath(string $folder)` | Safe root-prefixed path |
| `setIsPublicAttribute(bool $value)` | Synchronize boolean and enum visibility |
| `setVisibilityAttribute(MediaVisibility|string $visibility)` | Synchronize enum and boolean visibility |
| `newEloquentBuilder($query)` | Return the package `MediaBuilder` |

Load `imageVariations` before calling variation lookup helpers in loops, and load `associations` before calling `getUsagesSummary()` repeatedly.

## DTO reference

### Shared input DTOs

| DTO | Fields |
| --- | --- |
| `MediaFilter` | `search`, `type`, `disk`, `isPublic`, `collection`, `tag`, `folder`, `mimeType`, `extension`, `locale`, `associableType`, `perPage`, `page`, `sortBy`, `sortDirection` |
| `MediaActorData` | `type`, `id`, `system`, `signed`; use `fromAuthenticatable()` or `signed()` where appropriate |
| `MediaScanResultData` | `clean`, `mimeType`, `extension`, `size`, lowercase SHA-256 `checksum`, `diagnostics` |
| `UpdateMediaPayload` | optional `isPublic`, `tags`, `metadata`, `translations`, and `translationMode` |

Construct externally supplied DTOs with `validateAndCreate()` or another validated boundary. Direct constructors are appropriate only for trusted, already-normalized application values.

### Multipart DTOs

| DTO | Fields |
| --- | --- |
| `InitiateMultipartUploadData` | `disk`, display `filename`, declared `mimeType`, expected `size`, lowercase SHA-256 `checksum`, `visibility`, optional `folder` |
| `MultipartUploadSessionData` | server-issued `uploadId`, disk/object identity, display metadata, expected integrity, uploader identity, expiry, minimum part size, maximum parts |
| `SignMultipartPartData` | `uploadId`, one-based `partNumber`, lowercase SHA-256 `checksum`, `byteLength` |
| `SignedMultipartPartData` | `partNumber`, signed `url`, required `headers`, `expiresAt` |
| `CompletedMultipartPartData` | `partNumber`, provider `etag`, optional checksum |
| `CompleteMultipartUploadData` | `uploadId`, ordered list of completed part receipts |
| `CompletedMultipartObjectData` | provider path, checksum, size, MIME, optional object identity; gateway-facing |

The client never supplies authoritative disk, path, uploader, expiry, or object identity during sign, complete, or abort. Those operations reload the persisted session by opaque upload ID.

### Display DTOs

| DTO | Intended audience |
| --- | --- |
| `PublicMedia`, `PublicMediaImage`, `PublicMediaImageSize`, `PublicMediaFile` | Public rendering without storage or security-boundary fields |
| `MediaPayload` and nested image/document/file DTOs | Rich trusted application rendering |
| `MediaLibraryItem` | Privileged management listings |
| `MediaUsage` | Generic association usage |
| `MediaImageVariationPayload` | Named variation details |

## Focused Actions

The facade covers common application operations. Advanced consumers may inject these focused Actions directly:

| Action | `execute()` contract |
| --- | --- |
| `UploadMediaAction` | Validated ordinary upload and persistence |
| `AttachMediaAction` | Attach available media |
| `DetachMediaAction` | Detach one owner/collection |
| `ReusePublicMediaAction` | Reuse a public shared asset |
| `DeleteMediaAction` | Soft-delete and post-commit storage cleanup |
| `ReplaceMediaFileAction` | Stage, verify, swap, and clean up |
| `RenameMediaAction` | Update display filename |
| `UpdateMediaMetadataAction` | Update canonical and localized metadata |
| `MutateMediaTagsAction` | Add/remove normalized tags |
| `BulkDeleteMediaAction` | Delete under sorted media locks |
| `BulkMoveMediaAction` | Copy–verify–swap–delete folder movement |
| `BulkTagMediaAction` | Add tags under sorted locks |
| `GenerateImageVariationAction` | Revision-aware named conversion |
| `FinalizeMediaScanAction` | Attested scan transition |
| `InitiateMultipartUploadAction` | Persist and initiate direct upload |
| `SignMultipartPartAction` | Validate and sign one part |
| `CompleteMultipartUploadAction` | Recoverable, idempotent completion |
| `AbortMultipartUploadAction` | Idempotent actor-owned abort |

Upload, attach, detach, delete, and reuse also have focused contracts. Application code that replaces one of those contracts is honored consistently by the model trait, facade, package controllers, and lifecycle services.

## Exceptions and transaction semantics

Expected domain failures include:

| Exception | Meaning |
| --- | --- |
| `FileUnacceptableForCollection` | File violates slot or ingestion constraints |
| `MediaUploadException` | Upload, source, scan, path, or storage validation failed |
| `MediaInUseException` | Mutation would violate shared-asset integrity; HTTP conflict |
| `MediaNotReusableException` | A private asset was passed to the public reuse API |
| `ConversionFailedException` | Variation processing or persistence failed |
| `DiskNotDefinedException` | Referenced disk is unavailable |
| Eloquent `ModelNotFoundException` | UUID or multipart session does not exist |

Actions own their database transactions. New objects are removed on rollback; superseded objects are removed only after the real outer commit. Events, jobs, and synchronous variations also wait for the real outer commit. A post-commit cleanup failure is logged as a recoverable orphan and is discoverable with `nvl:media:reconcile --orphans`; it is not reported as a failed database transaction.

## Related references

- [HTTP API](http-api.md)
- [Configuration](configuration.md)
- [Extension contracts and events](extending.md)
- [Image variations and queues](images-and-queues.md)
- [S3 and object storage](s3.md)
- [Command reference](commands.md)
