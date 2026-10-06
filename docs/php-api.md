# PHP API reference

This reference documents the supported PHP integration surface of NVL Media. The shortest path is the `HasNvlMedia` contract plus `InteractsWithNvlMedia`; application services may use `MediaLibraryContract` or the `Nvl\Media\Facades\Media` facade; advanced integrations may inject a focused Action or contract.

## Choosing an entry point

| Entry point | Best for | Authorization boundary |
| --- | --- | --- |
| `InteractsWithNvlMedia` | Namespaced model entry point and independent lifecycle | Authorize in the calling controller, job, or service |
| `InteractsWithMedia` | Deprecated NVL-only forwarding surface through major 5 | Same NVL owner implementation |
| `Media` facade | Concise application orchestration | Authorize before mutations with a policy or `Media::allows()` |
| `MediaLibraryContract` | Constructor injection and boundary replacement | Same behavior as the facade |
| Focused contracts and Actions | Advanced integration or replacing one operation | Caller-owned |
| Management HTTP API | Authenticated library interfaces | Package policy and configured middleware |

Facade and direct PHP mutation calls are trusted application-service calls. They enforce ingestion, scanning, storage, integrity, locking, and transaction policy, but they do not infer an ambient actor for authorization.

## Namespaced owner capability

Implement `HasNvlMedia` and use `InteractsWithNvlMedia`. The trait adds only `nvlMedia()`, `nvlMediaAssociations()`, `registerNvlMedia()` and `bootInteractsWithNvlMedia()` to the model. Its private state has the `nvl` prefix. The boot hook runs only NVL deletion; it preserves soft-deleted owners and does not call a third-party library's methods.

```php
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Traits\InteractsWithNvlMedia;

final class Article extends Model implements HasNvlMedia
{
    use InteractsWithNvlMedia;

    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        $media->slot('cover')->oneToOne();
        $media->conversion('card')->width(640)->performOnSlots('cover');
    }
}

$asset = $article->nvlMedia()->add($upload)->slot('cover');
$cover = $article->nvlMedia()->first('cover');
```

`nvlMedia()` returns one `MediaOwner` for the actual model instance, keeping registration independent of upload collaborators. It never wraps the owner in a different Eloquent model. `nvlMediaAssociations()` is an internal storage relation. For owner lists use `ListOwnerMediaSlotsContract` with a `MediaActorData` and explicit `MediaBatchAuthorization`; for host existence filtering use the authorized `hasNvlMedia` scope shown below.

| Owner API method | Contract |
| --- | --- |
| `add()`, `copy()` | Build an upload from a file; copy preserves an explicit local original |
| `fromRequest()`, `fromUrl()`, `fromBase64()`, `fromString()`, `fromBinary()`, `fromDisk()` | Use the same bounded source and ingestion pipeline as the legacy methods |
| `attach()`, `detach()` | Add/remove an NVL association through the replaceable Actions; detach retains the asset |
| `sync()` | Atomically reconcile one collection's associations and order under its owner lock; retained assets are not deleted |
| `reuse()` | Associate an existing public reusable asset |
| `all()`, `first()`, `last()`, `has()` | Read one collection with optional attribute/callback filters; honor eager NVL associations |
| `firstUrl()`, `lastUrl()`, `firstPath()`, `lastPath()` | Return one asset URL/path or the registered fallback |
| `buildUrl()`, `buildPublicUrl()`, `firstTemporaryUrl()` | Use the existing centralized signed/private/public URL behavior |
| `fallbackUrl()`, `fallbackPath()` | Resolve one slot's fallback |
| `slot()`, `slots()`, `conversions()`, `registration()` | Inspect registered NVL definitions; conversions may depend on the supplied NVL asset |
| `clear()`, `clearExcept()`, `deleteAll()` | Remove owner associations and delete an asset only when it is no longer shared |
| `delete()` | Delete a specified NVL asset through the global delete Action |
| `order()`, `modelClass()` | Set collection order or return the NVL Media class |
| `deleteOwnerPreservingMedia()` | Delete the owner while preserving its NVL references; other libraries retain their own deletion policy |

The registration object exposes `slot()`, `conversion()`, `getSlot()`, `slots()` and `conversions()`. Definitions are local to one owner. NVL internal query/lifecycle/cache seams use `query()`, `associations()`, `ownerDeleted()`, `owns()` and `invalidate()`; application mutations should use the methods above.

For Spatie coexistence, implement Spatie's distinct `HasMedia` plus NVL's `HasNvlMedia`, and use Spatie's `InteractsWithMedia` plus NVL's `InteractsWithNvlMedia`. Each retains independent conversion hooks and deletion listeners. The standalone fixture installs the real third-party package only in test tooling.

## Deprecated NVL-only model surface

`HasMedia` extends the new capability and the supplied `InteractsWithMedia` trait forwards the methods below to the same owner API. Both remain through major 5, with removal in major 6. Legacy `registerMediaSlots()` and `registerMediaConversions()` overrides feed `registerNvlMedia()`; no separate implementation or duplicate lifecycle listener is installed.

## Legacy NVL-only media-enabled model

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
| `$owner->addMediaFromBinary($contents, $filename, ...$mimes)` | `Media::fromBinary($owner, $contents, $filename, ...$mimes)` | Materializes generated bytes, detects MIME from content, and preserves the supplied display filename |
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
| `Media::fromBinary($owner, $contents, $filename, ...$allowedMimeTypes)` | `MediaAdder` | Resolve generated binary bytes through MIME detection |
| `Media::fromDisk($owner, $key, ?$disk)` | `MediaAdder` | Resolve a disk object |
| `Media::paginate($filters = [], ?$actor = null, $includeVariations = false)` | `LengthAwarePaginator<Media>` | Filter and visibility-scope the library; page size is capped by configuration |
| `Media::findOrFail($id, $includeVariations = true)` | `Media` | Find a live media row and eager-load display relations |
| `Media::urlIfExists(?$media, $variation = '')` | `?string` | Return `null` when the row or canonical backing object is missing |
| `Media::usages($id)` | `Collection<MediaUsage>` | Return association usages |
| `Media::attach($media, $owner, $collection = 'default', ?$locale = null, ?$order = null, $metadata = [], $dispatchVariations = true)` | `MediaAssociation` | Idempotently attach available media |
| `Media::reuse($media, $owner, $collection = 'default', ?$locale = null, ?$order = null, $metadata = [], $dispatchVariations = true)` | `Media` | Attach an existing public reusable asset and return it |
| `Media::detach($media, $owner, ?$collection = null)` | `int` | Remove matching associations and return the count |
| `Media::delete($media, $force = false)` | `bool` | Soft-delete the record and schedule physical cleanup after commit |
| `Media::replace($media, $file)` | `Media` | Replace the source through stage–verify–swap semantics |
| `Media::rename($media, $filename)` | `Media` | Change the display filename only |
| `Media::relocate($media, $disk, $visibility, ?$expectedRevision = null)` | `Media` | Copy and verify one original plus variations, atomically swap disk/visibility, and clean the losing side after transaction outcome |
| `Media::updateMetadata($media, $data)` | `Media` | Apply `UpdateMediaPayload` atomically |
| `Media::generateVariation($media, $definition, ?$expectedRevision = null)` | `?MediaImageVariation` | Generate or replace one named variation; stale work returns `null` |
| `Media::finalizeScan($media, $result)` | `Media` | Finalize a `pending_scan` object using scanner attestation |
| `Media::initiateMultipart($data, $actor)` | `MultipartUploadSessionData` | Create a persisted direct-upload session |
| `Media::signMultipartPart($part, $actor)` | `SignedMultipartPartData` | Sign one declared part for its owning actor |
| `Media::completeMultipart($completion, $actor)` | `Media` | Idempotently complete and verify a persisted session |
| `Media::abortMultipart($uploadId, $actor)` | `void` | Idempotently abort an unfinished session |
| `Media::allows($actor, $ability, ?$media = null, ?$owner = null)` | `bool` | Evaluate the same authorization bridge used by policies and queries |

Attachment and public reuse reload the persisted media row under the mutation
lock and a database row lock. Availability is checked on that current row;
public reuse also checks visibility in the same transaction as attachment.
Previously loaded model instances supply identity, not authoritative lifecycle
or visibility state. These integrity checks do not replace caller authorization.
The concrete `AttachMediaAction::execute()` accepts an optional trailing
`requirePublic: true` for the reuse workflow; `ReusePublicMediaAction` supplies
it automatically. Existing attach calls and the `AttachMediaContract` signature
are unchanged.

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

Allowed sort columns are `created_at`, `updated_at`, `filename`, `size`, `type`, `extension`, and `mime_type`. Sort direction is `asc` or `desc`; `perPage` is capped at `nvl-media.query.maximum_page_size`, never above 100 in the shipped configuration.

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

## Deprecated model trait reference

### Registration and relationships

| Method | Contract |
| --- | --- |
| `bootInteractsWithMedia()` | Deprecated no-op; nested `bootInteractsWithNvlMedia()` registers the single NVL deletion listener |
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
| `addMediaFromBinary(string $contents, string $filename, string ...$allowedMimeTypes)` | `MediaAdder` |
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

Soft-deleting an owner preserves associations for restoration. Force deletion follows `nvl-media.delete_media_on_model_delete` unless `deletePreservingMedia()` is used deliberately.

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

Path templates support `{model_type}`, `{model_id}`, `{id}`, `{collection}`, `{slug}`, `{date}`, `{year}`, `{month}`, `{day}`, and model attributes. The resolved folder remains constrained beneath `nvl-media.root_folder`.

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

Consumers use Actions and authorized readers/scopes. The model exposes only the eight identity methods and the exact in-memory fields listed in README. The relationships, scopes, and derived helpers documented below describe package-internal storage behavior; they do not grant consumer queries, traversal, or serialization.

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
| `GetOwnerMediaSlotAction` | Authorized DTO read for one registered single-file owner slot |
| `ReplaceOwnerMediaSlotAction` | Adopt compatible staged Media and atomically replace one slot |
| `ClearOwnerMediaSlotAction` | Detach one slot and delete an orphaned exclusive asset after commit |
| `CopyOwnerMediaSlotAction` | Verify source bytes and canonically ingest a distinct destination asset |

Upload, attach, detach, delete, and reuse also have focused contracts. Application code that replaces one of those contracts is honored consistently by the model trait, facade, package controllers, and lifecycle services.

### Owner-slot workflows and operation identity

The four owner-slot Actions accept `MediaActorData`, a persisted
`Model&HasNvlMedia`, and a registered single-file slot name. Get returns
`?MediaLibraryItem`; replace and copy return `MediaLibraryItem`; clear returns
`void`. Mutation Actions accept an optional UUID idempotency key. They own
authorization, compatibility/staging checks, owner and Media locks,
association transitions, exclusive-orphan cleanup, exact replay, and immutable
result projection. Copy creates a distinct identity through the upload pipeline
and inherits only allowlisted scalar metadata plus normalized tags.

`MediaOwnerSlotIdempotency` is the low-level durable boundary for
actor-aware owner-slot workflows. `begin()` accepts a UUID key, actor,
persisted owner, slot, operation enum, and nested scalar payload. It returns an
immutable `MediaOwnerSlotOperationClaim` containing the operation UUID, request
hash, replay flag, and nullable result Media UUID.

Canonical map ordering makes equivalent payloads stable. A completed exact
request replays; an in-progress request or a key reused for another request
fails closed; an exact failed request may be reclaimed. `complete()` and
`fail()` require the claim's request hash and perform one terminal transition.
`renew()` extends a processing lease for bounded long-running work. An expired
processing request may be reclaimed with a new operation UUID, permanently
invalidating the stale claim. Failure values are bounded machine codes, never
exception messages.

Ledger work on another database connection cannot share an atomic transaction
with Media or owner persistence. Treat that configuration as a recoverable
saga: the mutation must be safe to reconcile or retry after a crash before
`complete()`. `nvl:media:owner-slots:prune` removes only expired completed or
failed rows in chunks of at most 1,000. Consumers should call the Actions and
never write or query the operation model directly.

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

## Runtime storage identities

Media models expose Laravel's `getTable()` and `getConnectionName()` methods using the effective canonical `nvl-media.tables.*`, `nvl-media.connection` and Core defaults. `MediaOwnerSlotOperation::getConnectionName()` also honors `media.connections.owner_slot_operations`; null inherits the Media/Core connection. An explicit model `setConnection()` takes precedence. Use the model's connection and table when building custom queries.

## Batched owner slots

`ListOwnerMediaSlotsContract::execute(array $owners, array $slots, MediaActorData $actor, ?string $locale = null): OwnerMediaSlots`
is the public many-owner read boundary. Bind an explicit `MediaBatchAuthorization` SQL adapter before use;
imperative `MediaAuthorization` callbacks are not converted into batch grants. Unsupported adapters throw
`MediaBatchReadException` before Media storage reads. Empty owner input returns an object map without SQL,
even when no adapter is configured.

Register the host policy in its service provider, then read the returned DTO map:

```php
use Nvl\Media\Contracts\ListOwnerMediaSlotsContract;
use Nvl\Media\Contracts\MediaBatchAuthorization;

$this->app->bind(MediaBatchAuthorization::class, App\Authorization\MediaBatchPolicy::class);

$owners = Article::query()->limit(100)->get()->all();
$result = app(ListOwnerMediaSlotsContract::class)->execute($owners, ['document'], $actor, 'bg');
foreach ($result->order as $identity) {
    $items = $result->owners->{$identity['type']}->{$identity['id']}->slots->document;
    // Each item contains safe display fields; private URLs remain null.
}
```

Here `$actor` is the application's resolved `MediaActorData`, and every admitted
Article registers the requested `document` slot. The policy implements all four
query-free SQL methods described in [extending](extending.md#explicit-batch-view-authorization).

The reader accepts at most 100 persisted input models and 20 distinct registered slot names. It captures native
morph/key pairs, deduplicates identical input, reloads each concrete class through canonical host scopes, and
applies registered TenantBoundary ownership when enabled. Every canonical owner must register every requested
slot, including attribute-dependent declarations. Slot identity is the actual association `collection`; historical
`metadata.slot` does not determine membership.

Available, nondeleted assets and active associations are authorized before `ROW_NUMBER()` ranking. Items follow
ascending association order with association ID as the stable tie breaker. Each owner/slot transfers at most its
declared ceiling, capped at ten; the total ceiling is 20,000 association rows. SQLite, PostgreSQL, MySQL 8.4,
and MariaDB 12.3 window queries are supported. The requested/fallback locale chain is limited to ten rows per
asset and validated before SQL. Projection resolves loaded fields without lazy translation or variation queries.

`OwnerMediaSlots::$owners` is an object map of native morph types to string owner IDs; `$order` carries first-request
identity order. Each `OwnerMediaSlotData::$slots` is an object map including requested empty lists, even for numeric
slot names. `OwnerMediaSlotItemData` contains exactly `associationId`, `mediaId`, `title`, `altText`, `caption`,
`mimeType`, `size`, `isPublic`, and nullable `url`. No storage internals, uploader, usage counts, or raw metadata are exposed.

Private assets always have `url = null`, including actors granted View. Obtain private Download links separately
through the existing explicitly authorized delivery boundary. Public originals use `MediaUrlResolver::publicUrl($media, [])`;
an empty resolver result becomes null. This reader loads no image variations.

`InteractsWithNvlMedia::scopeHasNvlMedia(Builder $query, string $slot, MediaActorData $actor, MediaBatchAuthorization $policy): Builder`
adds a correlated existence predicate without replacing host selections or global scopes. Call it as
`Owner::query()->hasNvlMedia($slot, $actor, $policy)`. It rejects changed actual query connections, base FROM/aliases,
and unions, retains canonical soft deletion and tenant ownership even after caller scope removal, and uses exact
text correlation for integer, UUID, and string keys. It is independent of Spatie's `hasMedia()` API.

The normal localized read budget is one canonical query per concrete owner class plus three Media queries when
assets exist. A cold disabled-Tenancy call adds one fixed installation safety probe. Empty associations need no
asset/translation reads; no query scales with owner count or projected item count.


## Replaceable workflows and scripted uploads

Inject `MediaLibraryContract` for the complete Media API, or the focused workflow
contract (for example `ReplaceOwnerMediaSlotContract`) for one operation. Selected
Action contracts retain the native `execute()` arguments and results; the default
implementations retain their concrete constructors and final/readonly qualifiers.
Defaults use conditional bindings so an earlier host binding remains authoritative.
`MediaLibraryContract` and `MediaCatalogImport` retain scoped defaults.

The eight library source methods return `MediaUploadBuilder`: `add`, `copy`,
`fromRequest`, `fromUrl`, `fromBase64`, `fromString`, `fromBinary`, and `fromDisk`.
The real library still returns `MediaAdder` covariantly. Type consumer orchestration
against the builder interface when replacement is required.

```php
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Models\Media;
use Nvl\Media\Testing\FakeMediaLibrary;
use Nvl\Media\Testing\FakeMediaUploadBuilder;
use Nvl\Support\Testing\FakeCall;

// $app is the explicit application container; $owner is an in-memory media owner.
$asset = new Media;
$asset->forceFill(['id' => 'example-asset', 'is_public' => false]);
$builder = (new FakeMediaUploadBuilder)->willReturn('upload', $asset);
$fake = FakeMediaLibrary::fake($app)->willReturn('fromString', $builder);

$result = $app->make(MediaLibraryContract::class)
    ->fromString($owner, 'document bytes')
    ->usingSlot('document')
    ->asPublic(false)
    ->upload();

$builder->assertCalled('upload', fn (FakeCall $call): bool =>
    $call->arguments['options']['slot'] === 'document'
    && $call->arguments['options']['public'] === false);
```

Library scripts use the actual library method name and return its declared native
result. Every call consumes one FIFO result or exception and is recorded before
throwing or reporting exhaustion. Use `willThrow($method, $exception)` for failures,
`calls($method)` for ordered immutable `FakeCall` records, and
`assertCalled($method, $predicate, $times)` for exact counts. Predicates receive a
`FakeCall`; zero is a valid expected count and negative counts fail. Nullable and
void successes require an explicit `willReturn($method, null)`. Authorization and
URLs must be explicitly scripted, including `false` or `null`; they are never inferred.

Script each fresh `FakeMediaUploadBuilder` under its own `upload`, `slot`, or
`slotOnCloudDisk` terminal name. Each source entry initializes its returned fake
builder exactly once from the actual source arguments and owner identity. Reusing
that builder in another library entry fails without overwriting the original
context. An arbitrary host `MediaUploadBuilder` is returned unchanged. Repeated
terminals consume new scripts, recording one complete request per attempt.

Terminal `FakeCall::arguments` contains `arguments` (the native terminal arguments),
`source` (`method` and its original named `arguments`, excluding owner), `owner`
(`model`, native `id`, and morph `type`), and an `options` snapshot. Options retain
`slot`, `pendingSlot`, resolved `collection`, `fileName`, inert `fileNameSanitizer`,
`preserveOriginal`, `customProperties`, appended `tags`, `locale`, `order`, `disk`,
`folder`, nullable `public`, keyed `variations`, independent `skipVariations`,
`associationMeta` with the resolved slot, `allowDuplicates`, `uploaderId`, and
`uploaderType`. Later setters do not change earlier array snapshots. Model objects
and closures retain identity; they are not serialized or executed.

The fake performs no source reads, decoding, downloads, filesystem mutations,
scanning, SQL, or job dispatch, and does not resolve owner-slot defaults. `copy`
presets preservation, null slot names fall back while empty names remain empty,
and a terminal disk override remains for later terminals. Variation maps retain
supplied keys; property, metadata, and tag merges follow native `array_merge`.
Uploader identifiers use the native string conversion; owner keys retain their
native type. The cloud disk is captured from the explicit installation container,
with native `s3` fallback, rather than a global application.

Install before resolving consumer services. Installation binds the supplied
container's `MediaLibraryContract` instance and clears that facade accessor's cached
root; it does not change Laravel's global facade application. A fake installed
after normal scoped registration is forgotten on `forgetScopedInstances()`; a
pre-discovery host instance preserved by `scopedIf` remains host-owned. Scope flushes
do not automatically clear Laravel facade caches. Use a separate fake per container.

### Focused workflow interfaces

- `Nvl\Media\Contracts\AbortMultipartUploadContract` — `AbortMultipartUploadAction::execute()` arguments and results.
- `Nvl\Media\Contracts\AdoptSpatieMediaContract` — `AdoptSpatieMediaAction::execute()` arguments and results.
- `Nvl\Media\Contracts\BulkDeleteMediaContract` — `BulkDeleteMediaAction::execute()` arguments and results.
- `Nvl\Media\Contracts\BulkMoveMediaContract` — `BulkMoveMediaAction::execute()` arguments and results.
- `Nvl\Media\Contracts\BulkTagMediaContract` — `BulkTagMediaAction::execute()` arguments and results.
- `Nvl\Media\Contracts\ClearOwnerMediaSlotContract` — `ClearOwnerMediaSlotAction::execute()` arguments and results.
- `Nvl\Media\Contracts\CompleteMultipartUploadContract` — `CompleteMultipartUploadAction::execute()` arguments and results.
- `Nvl\Media\Contracts\CopyOwnerMediaSlotContract` — `CopyOwnerMediaSlotAction::execute()` arguments and results.
- `Nvl\Media\Contracts\FinalizeMediaScanContract` — `FinalizeMediaScanAction::execute()` arguments and results.
- `Nvl\Media\Contracts\GenerateImageVariationContract` — `GenerateImageVariationAction::execute()` arguments and results.
- `Nvl\Media\Contracts\GetOwnerMediaSlotContract` — `GetOwnerMediaSlotAction::execute()` arguments and results.
- `Nvl\Media\Contracts\GrantMediaToTenantContract` — `GrantMediaToTenantAction::execute()` arguments and results.
- `Nvl\Media\Contracts\ImportPlatformMediaContract` — `ImportPlatformMediaAction::execute()` arguments and results.
- `Nvl\Media\Contracts\InitiateMultipartUploadContract` — `InitiateMultipartUploadAction::execute()` arguments and results.
- `Nvl\Media\Contracts\MutateMediaTagsContract` — `MutateMediaTagsAction::execute()` arguments and results.
- `Nvl\Media\Contracts\RelocateMediaContract` — `RelocateMediaAction::execute()` arguments and results.
- `Nvl\Media\Contracts\RenameMediaContract` — `RenameMediaAction::execute()` arguments and results.
- `Nvl\Media\Contracts\ReplaceMediaFileContract` — `ReplaceMediaFileAction::execute()` arguments and results.
- `Nvl\Media\Contracts\ReplaceOwnerMediaSlotContract` — `ReplaceOwnerMediaSlotAction::execute()` arguments and results.
- `Nvl\Media\Contracts\RevokeMediaTenantGrantContract` — `RevokeMediaTenantGrantAction::execute()` arguments and results.
- `Nvl\Media\Contracts\SignMultipartPartContract` — `SignMultipartPartAction::execute()` arguments and results.
- `Nvl\Media\Contracts\UpdateMediaMetadataContract` — `UpdateMediaMetadataAction::execute()` arguments and results.

Existing `UploadMediaContract`, `AttachMediaContract`, `DetachMediaContract`, `DeleteMediaContract`, `ReusePublicMediaContract`, and `ListOwnerMediaSlotsContract` remain the established seams. `MediaUploadBuilder` exposes all eighteen native fluent setters and the three native terminals; its internal fake initialization method is not a consumer API.
