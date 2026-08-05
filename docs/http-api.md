# HTTP API reference

NVL Media ships two independent HTTP surfaces:

- An optional authenticated management API for library interfaces.
- Asset-delivery routes for public and signed private binaries.

The management API is disabled by default. Asset delivery is enabled by default. Multipart orchestration is intentionally not exposed through these 1.x management routes; applications expose their own actor-aware endpoints around the typed multipart facade or Actions when direct uploads are enabled.

## Enable the management API

Publish the configuration and set:

```php
'routes' => [
    'api_enabled' => true,
    'api_prefix' => 'api/v1',
    'api_middleware' => ['api'],
    'management_middleware' => ['auth', 'throttle:60,1'],
],
```

The final base path is `{api_prefix}/media`. With shipped defaults it is `/api/v1/media`.

`api_middleware` wraps the complete route file. `management_middleware` wraps every management endpoint inside it. Production diagnostics reject publicly writable management routes. Replace `auth` with the application’s intended guard, such as `auth:sanctum`, when appropriate.

All API clients should send `Accept: application/json`. Upload and replacement requests use `multipart/form-data`; other mutations use JSON.

## Authorization

The package registers `MediaPolicy` for `Nvl\Media\Models\Media`.

| Policy method | Package ability | Used by |
| --- | --- | --- |
| `viewAny` | `MediaAbility::List` | Index |
| `view` | `MediaAbility::View` | Show, usages, variations |
| `create` | `MediaAbility::Upload` | Ordinary upload |
| `update` | `MediaAbility::Mutate` | Update, rename, replace, reorder, tag, move |
| `delete` | `MediaAbility::Delete` | Delete and bulk delete |
| `attach` / `detach` | `MediaAbility::Associate` | Association mutations |
| `regenerate` | `MediaAbility::Mutate` | Variation regeneration |
| `download` | `MediaAbility::Download` | Management download |

By default, public assets are readable and private assets are limited to their typed uploader. Configured global Spatie-compatible roles or permissions may grant cross-owner access. Association endpoints additionally authorize mutation of the target associable model using `media.associable_mutation_abilities`.

## Endpoint index

All route names have the `nvl.media.management.` prefix.

| Method | Path | Route name | Success |
| --- | --- | --- | --- |
| `GET` | `/media` | `nvl.media.management.index` | `200` |
| `POST` | `/media` | `nvl.media.management.store` | `201` |
| `POST` | `/media/reorder` | `nvl.media.management.reorder` | `200` |
| `POST` | `/media/bulk` | `nvl.media.management.bulk` | `200` |
| `GET` | `/media/{media}` | `nvl.media.management.show` | `200` |
| `PUT` | `/media/{media}` | `nvl.media.management.update` | `200` |
| `PATCH` | `/media/{media}` | `nvl.media.management.update.patch` | `200` |
| `DELETE` | `/media/{media}` | `nvl.media.management.destroy` | `200` |
| `POST` | `/media/{media}/attach` | `nvl.media.management.attach` | `200` |
| `POST` | `/media/{media}/detach` | `nvl.media.management.detach` | `200` |
| `GET` | `/media/{media}/variations` | `nvl.media.management.variations` | `200` |
| `POST` | `/media/{media}/regenerate` | `nvl.media.management.regenerate` | `200` |
| `PATCH` | `/media/{media}/rename` | `nvl.media.management.rename` | `200` |
| `GET` | `/media/{media}/usages` | `nvl.media.management.usages` | `200` |
| `GET` | `/media/{media}/download` | `nvl.media.management.download` | Binary `200` |
| `POST` | `/media/{media}/replace` | `nvl.media.management.replace` | `200` |

Paths in the rest of this document include the shipped `/api/v1` prefix.

## Common response shapes

### Management media resource

Detail and mutation responses use this privileged representation:

```json
{
  "id": "018f...",
  "filename": "campaign.jpg",
  "extension": "jpg",
  "mimeType": "image/jpeg",
  "size": 245760,
  "humanReadableSize": "240 KB",
  "disk": "s3",
  "folder": "campaigns/018f...",
  "isPublic": true,
  "type": "image",
  "digest": "lowercase-sha256",
  "tags": ["campaign"],
  "metadata": {"credit": "Studio"},
  "uploadedBy": "42",
  "url": "https://example.test/media/assets/018f...",
  "previewUrl": "https://example.test/media/assets/018f...?version=0123456789abcdef&v=thumb",
  "imageVariations": [
    {
      "id": "018f...",
      "label": "thumb",
      "width": 160,
      "height": 160,
      "size": 9216,
      "format": "webp",
      "quality": 82,
      "url": "https://example.test/media/assets/018f...?version=0123456789abcdef&v=thumb"
    }
  ],
  "translations": {
    "en": {
      "title": "Campaign",
      "alt": "Campaign photograph",
      "caption": null,
      "description": null
    }
  },
  "associationsCount": 1,
  "usages": [
    {
      "id": "018f...",
      "type": "articles",
      "modelId": "018f...",
      "collection": "gallery",
      "locale": "en",
      "order": 0
    }
  ],
  "fileExists": true,
  "createdAt": "2026-07-28T12:00:00.000000Z",
  "updatedAt": "2026-07-28T12:00:00.000000Z"
}
```

Conditional keys appear only when their relationships or counts were loaded. URL fields may be `null` when delivery cannot be resolved. This is a privileged resource: it includes disk, folder, digest, uploader, and internal metadata. Do not proxy it into a public page; use `PublicMedia` for public rendering.

### Variation resource

```json
{
  "id": "018f...",
  "label": "thumb",
  "width": 160,
  "height": 160,
  "size": 9216,
  "format": "webp",
  "quality": 82,
  "url": "https://example.test/media/assets/018f...?version=0123456789abcdef&v=thumb"
}
```

### Errors

Laravel authentication, authorization, route binding, and validation semantics apply:

| Status | Meaning |
| --- | --- |
| `401` | Authentication middleware rejected the request |
| `403` | Media policy, target-owner policy, or signed URL rejected the request |
| `404` | Media, target model, session, or physical download object was not found |
| `409` | Mutation would violate shared-public-asset integrity |
| `422` | Validation, ingestion, scanner, path, variation, or upload policy rejected input |
| `500` | Unexpected storage, disk configuration, download, or conversion failure |

Standard validation responses use Laravel’s JSON shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "files.0": ["The files.0 field is invalid."]
  }
}
```

Domain errors generally return:

```json
{
  "message": "Human-readable failure description."
}
```

The unsupported-variation response also includes stable code `variations_unsupported`.

## List media

`GET /api/v1/media`

Query parameters:

| Parameter | Type | Default | Constraint |
| --- | --- | --- | --- |
| `search` | string | `null` | Maximum 255 characters |
| `type` | string | `null` | `image`, `document`, `video`, `audio`, `archive`, `code`, `other` |
| `disk` | string | `null` | Maximum 25 characters |
| `isPublic` | boolean | `null` | Public/private filter |
| `collection` | string | `null` | Maximum 50 characters |
| `tag` | string | `null` | Maximum 50 characters |
| `folder` | string | `null` | Maximum 255 characters |
| `mimeType` | string | `null` | Maximum 100 characters |
| `extension` | string | `null` | Maximum 10 characters |
| `locale` | string | `null` | Maximum 5 characters |
| `associableType` | string | `null` | Morph class/type filter |
| `perPage` | integer | `25` | `1..100`, also capped by `media.query.maximum_page_size` |
| `page` | integer | `1` | Minimum 1 |
| `sortBy` | string | `created_at` | `created_at`, `updated_at`, `filename`, `size`, `type`, `extension`, `mime_type` |
| `sortDirection` | string | `desc` | `asc` or `desc` |
| `include_variations` | boolean | `true` | Include `imageVariations` in each item |

Visibility is always actor-scoped after filtering. Normal actors see public assets plus their own private assets. `ListAll` access exposes every live row but not soft-deleted rows.

Response:

```json
{
  "data": {
    "media": {
      "items": [
        {
          "id": "018f...",
          "filename": "campaign.jpg",
          "title": "Campaign",
          "extension": "jpg",
          "mimeType": "image/jpeg",
          "size": 245760,
          "humanReadableSize": "240 KB",
          "disk": "s3",
          "folder": "campaigns",
          "collection": "gallery",
          "isPublic": true,
          "type": "image",
          "tags": ["campaign"],
          "associationsCount": 1,
          "createdAt": "2026-07-28T12:00:00Z",
          "updatedAt": "2026-07-28T12:00:00Z",
          "previewUrl": "https://example.test/media/assets/018f...?version=0123456789abcdef&v=thumb",
          "url": "https://example.test/media/assets/018f...",
          "imageVariations": []
        }
      ],
      "links": {
        "first": "https://example.test/api/v1/media?page=1",
        "last": "https://example.test/api/v1/media?page=4",
        "prev": null,
        "next": "https://example.test/api/v1/media?page=2"
      },
      "meta": {
        "currentPage": 1,
        "lastPage": 4,
        "perPage": 25,
        "total": 91
      }
    },
    "filterOptions": {},
    "dialogConfig": {
      "allowedTypes": ["image", "document", "video", "audio", "archive", "code", "other"],
      "allowedCollections": ["default"],
      "includePrivate": true,
      "preload": true,
      "upload": {
        "enabled": true,
        "collection": "default",
        "isPublic": false
      }
    }
  }
}
```

## Upload media

`POST /api/v1/media`

Content type: `multipart/form-data`.

| Field | Type | Required | Constraint |
| --- | --- | --- | --- |
| `files[]` | file list | yes | 1 to `media.max_files_per_upload`; each file passes package size/type policy |
| `collection` | string | no | Default `default`, maximum 50 |
| `disk` | string | no | Must be configured and in `media.allowed_disks` |
| `isPublic` | boolean | no | Default `false` |
| `tags[]` | string list | no | Each maximum 50 |

The authenticated user must be an Eloquent model because its morph type and identifier establish uploader ownership.

```bash
curl -X POST "https://example.test/api/v1/media" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -F "files[]=@cover.jpg" \
  -F "collection=gallery" \
  -F "disk=s3" \
  -F "isPublic=1" \
  -F "tags[]=campaign"
```

Successful response (`201`):

```json
{
  "data": {
    "items": [
      {
        "id": "018f...",
        "filename": "cover.jpg"
      }
    ]
  },
  "message": "Media uploaded successfully."
}
```

Each item is a management media resource. The controller performs early file validation, while the Action re-runs authoritative MIME, extension, size, SVG, scanner, checksum, disk, and storage policy.

## Show media

`GET /api/v1/media/{media}`

Optional query parameter `include_variations` defaults to `true`. The response is:

```json
{
  "data": {
    "id": "018f...",
    "filename": "campaign.jpg"
  }
}
```

`data` is the complete management media resource.

## Update metadata

`PUT /api/v1/media/{media}` or `PATCH /api/v1/media/{media}`

All fields are optional:

| Field | Type | Behavior |
| --- | --- | --- |
| `is_public` | boolean | Change visibility; shared public assets cannot become private while multiply associated |
| `tags` | string list or `null` | Replace canonical tags |
| `metadata` | object or `null` | Replace canonical metadata |
| `translations` | locale-keyed object or `null` | Write `title`, `alt`, `caption`, `description` |
| `translationMode` | `patch` or `replace` | Default `patch` |

```json
{
  "is_public": true,
  "tags": ["campaign", "approved"],
  "metadata": {
    "credit": "Studio"
  },
  "translations": {
    "en": {
      "title": "Campaign",
      "alt": "Campaign photograph",
      "caption": null,
      "description": null
    }
  },
  "translationMode": "patch"
}
```

The response is `{"data": <management-media-resource>}`.

## Delete media

`DELETE /api/v1/media/{media}`

Successful response:

```json
{
  "data": {
    "deleted": true
  },
  "message": "Media deleted successfully."
}
```

The HTTP endpoint performs an ordinary, non-forced global delete. A multiply associated public asset returns `409`; detach an individual usage or perform an explicitly authorized administrative force delete through the PHP API.

Deletion soft-deletes the database row first. Files are removed after the real outer commit. Cleanup failures become recoverable orphan conditions.

## Rename media

`PATCH /api/v1/media/{media}/rename`

```json
{
  "filename": "campaign-final.jpg"
}
```

`filename` is required, maximum 255 characters, and cannot contain `/` or `\`. Renaming changes presentation metadata only; storage identity and URL safety remain opaque and canonical.

The response contains the management media resource and a success message.

## Replace media

`POST /api/v1/media/{media}/replace`

Content type: `multipart/form-data`.

| Field | Type | Required |
| --- | --- | --- |
| `file` | file | yes |

Replacement applies the persisted association-slot policies, stages and verifies the new object, swaps database state transactionally, removes staged output on rollback, and removes the previous original and variations after commit.

The response contains the management media resource and a success message.

## Attach media

`POST /api/v1/media/{media}/attach`

```json
{
  "associableType": "App\\Models\\Article",
  "associableId": "018f...",
  "collection": "gallery",
  "locale": "en",
  "order": 0
}
```

| Field | Required | Constraint |
| --- | --- | --- |
| `associableType` | yes | Must resolve through `media.allowed_associable_types` |
| `associableId` | yes | Existing target identifier |
| `collection` | no | Default `default`, maximum 50 |
| `locale` | no | Maximum 5 |
| `order` | no | Integer, minimum 0 |

The target model must implement `HasMedia`. The actor must have `Associate` access to the media and the configured mutation ability on the target model.

Response:

```json
{
  "data": {
    "association": {
      "id": "018f...",
      "media_id": "018f...",
      "associable_type": "articles",
      "associable_id": "018f...",
      "collection": "gallery",
      "locale": "en",
      "order": 0
    }
  },
  "message": "Media attached successfully."
}
```

## Detach media

`POST /api/v1/media/{media}/detach`

```json
{
  "associableType": "App\\Models\\Article",
  "associableId": "018f...",
  "collection": "gallery"
}
```

`collection` is optional. When omitted, every association between the media and target model is removed.

Response:

```json
{
  "data": {
    "detachedCount": 1
  },
  "message": "Media detached successfully."
}
```

## Reorder associations

`POST /api/v1/media/reorder`

```json
{
  "mediaIds": [
    "018f...second",
    "018f...first"
  ],
  "associableType": "App\\Models\\Article",
  "associableId": "018f...",
  "collection": "gallery"
}
```

`mediaIds` is a non-empty UUID list. Its zero-based array positions become association order values. Every media item requires update access, and the target type must be allowlisted, media-enabled, present, and authorized.

Response:

```json
{
  "data": {
    "reordered": true
  },
  "message": "Media reordered successfully."
}
```

## Bulk operations

`POST /api/v1/media/bulk`

Delete:

```json
{
  "action": "delete",
  "ids": ["018f...", "018f..."]
}
```

Tag:

```json
{
  "action": "tag",
  "ids": ["018f...", "018f..."],
  "tags": ["campaign", "approved"]
}
```

Move:

```json
{
  "action": "move",
  "ids": ["018f...", "018f..."],
  "folder": "archive/2026"
}
```

`action` must be `delete`, `tag`, or `move`; `ids` is a non-empty UUID list. Tags are required for `tag`. Folder is required for `move`, maximum 255 characters, must remain a safe relative path, and resolves beneath `media.root_folder`.

Bulk locks are acquired in sorted UUID order. Move performs copy–verify–database-swap–delete for originals and variations.

Response:

```json
{
  "data": {
    "affected": 2
  },
  "message": "Bulk operation completed."
}
```

## List variations

`GET /api/v1/media/{media}/variations`

Response:

```json
{
  "data": [
    {
      "id": "018f...",
      "label": "thumb",
      "width": 160,
      "height": 160,
      "size": 9216,
      "format": "webp",
      "quality": 82,
      "url": "https://example.test/media/assets/018f...?version=0123456789abcdef&v=thumb"
    }
  ]
}
```

## Regenerate variations

`POST /api/v1/media/{media}/regenerate`

```json
{
  "variations": ["thumb", "medium"]
}
```

`variations` is optional. When omitted, all enabled configured definitions are considered. When supplied, each label is a string of at most 50 characters. Only named configured definitions can be generated.

Response:

```json
{
  "data": {
    "regenerated": [
      {
        "label": "thumb",
        "width": 160,
        "height": 160
      }
    ]
  },
  "message": "Media variations regenerated successfully."
}
```

Non-convertible media returns:

```json
{
  "message": "Variations are not supported for this media.",
  "code": "variations_unsupported"
}
```

with status `422`.

## List usages

`GET /api/v1/media/{media}/usages`

```json
{
  "data": [
    {
      "id": "018f...",
      "type": "articles",
      "modelId": "018f...",
      "collection": "gallery",
      "locale": "en",
      "order": 0
    }
  ]
}
```

Owner details are intentionally generic; the package does not expose arbitrary associated model attributes.

## Management download

`GET /api/v1/media/{media}/download`

On success, returns a streamed attachment using the media display filename and detected `Content-Type`.

| Outcome | Status |
| --- | --- |
| Authorized and physical object exists | Binary `200` |
| Policy denies download | JSON `403` |
| Physical object is absent or unreadable | JSON `404` |
| Unexpected storage error | JSON `500` |

## Asset delivery routes

Asset delivery is controlled independently:

```php
'routes' => [
    'assets_enabled' => true,
    'assets_prefix' => 'media',
],
```

### Public asset

Route name: `media.assets.show`

`GET /media/assets/{media}?version=0123456789abcdef&v=thumb`

Only public, usable media may be delivered. `version` is the authoritative content version emitted by the package URL builder, and `v` must be a named stored variation. Arbitrary transform parameters are rejected. Versioned URLs receive the configured immutable cache policy; a manually requested URL without `version` remains available for compatibility but is forced to revalidate. The route supports configured throttling, cache headers, conditional requests, byte ranges, and streamed remote-disk reads.

### Private asset

Route name: `media.private.show`

`GET /media/private/{owner}/{media}?expires=...&signature=...&v=thumb`

The URL must be generated by `$media->buildUrl()`, `$media->buildPrivateUrl()`, or `$media->getTemporaryUrl()`. It is a signed capability tied to the owner token and media UUID. An authenticated request may also be evaluated through the authorization contract where configured, but consumers should never assemble this path or signature manually.

Invalid or expired signatures return `403`. Private cache control defaults to `private, max-age=0, no-store`.

### Delivery parameters

The shipped `media.assets.allowed_parameters` contains only `v`. A variation label is part of the signed private URL. Adding ignored or unsigned transformation parameters would weaken the delivery contract and is unsupported.

## Multipart HTTP integration

The package’s persisted multipart API is exposed through `MediaLibraryContract` and focused Actions, not through the management route file. An application endpoint should:

1. Authenticate its actor.
2. Build `MediaActorData::fromAuthenticatable($request->user())`.
3. Validate and construct the relevant multipart DTO.
4. Invoke `Media::initiateMultipart()`, `Media::signMultipartPart()`, `Media::completeMultipart()`, or `Media::abortMultipart()`.
5. Return only the client-safe DTO.

Do not accept disk, object key, provider upload ID, uploader, expected integrity, or expiry during sign, complete, or abort. Only accept the opaque package upload ID plus required part declarations or completion receipts.

See [PHP API](php-api.md#multipart-dtos) and [S3 and object storage](s3.md).

## Related references

- [PHP API](php-api.md)
- [Configuration](configuration.md)
- [Extension contracts and events](extending.md)
- [Command reference](commands.md)
