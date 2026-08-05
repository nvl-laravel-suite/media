# Image variations and queues

NVL Media generates deterministic database-backed variations using Spatie Image. Configuration is enum-driven, workers receive the complete conversion definition, and every job is source-revision-aware so stale work cannot overwrite a replaced source.

## Drivers and formats

Choose a driver with `MEDIA_IMAGE_DRIVER=gd`, `imagick`, or `vips`. The corresponding runtime extension/library must be installed on every web and worker host that may process images.

The package supports JPEG, PNG, GIF, WebP, and AVIF outputs. Actual encoder support depends on the installed driver build. `nvl:media:doctor` checks the configured driver and, for GD, the required encoder functions.

```php
use Nvl\Media\Enums\ImageCompression;
use Nvl\Media\Enums\ImageFormat;

'image_formats' => [
    ImageFormat::Webp->value => [
        'compression' => ImageCompression::Lossy,
        'quality' => 82,
    ],
    ImageFormat::Avif->value => [
        'compression' => ImageCompression::Lossy,
        'quality' => 60,
    ],
],
```

Quality is bounded from 0 to 100 by `ConversionDefinition`. Lossless mode uses quality 100—the portable lossless control exposed by supported Spatie Image drivers. NVL Media does not expose encoder knobs it cannot apply consistently.

## Built-in presets

```php
use Nvl\Media\Enums\ImageFit;
use Nvl\Media\Enums\ImageFormat;
use Nvl\Media\Enums\ImagePreset;

'image_variation_presets' => [
    ImagePreset::Thumbnail->value => [
        'width' => 160,
        'height' => 160,
        'fit' => ImageFit::Crop,
        'format' => ImageFormat::Webp,
        'enabled' => true,
    ],
    ImagePreset::Small->value => [
        'max_size' => 480,
        'fit' => ImageFit::Max,
        'format' => ImageFormat::Webp,
        'enabled' => true,
    ],
    ImagePreset::Medium->value => [
        'max_size' => 960,
        'fit' => ImageFit::Max,
        'format' => ImageFormat::Webp,
        'enabled' => true,
    ],
],
```

`ImageFit::Crop` produces exact dimensions. `ImageFit::Max` fits inside the square bound, preserves aspect ratio, and does not upscale. A 2400×1600 source with `max_size=1200` becomes 1200×800; a 600×400 source stays 600×400.

The full-size `optimized` variation defaults to WebP, maximum 1200 pixels on either axis, and skips SVG/GIF. It is a variation; the verified source object is retained.

## Replacing or extending presets

The published configuration belongs to the application, so replace the preset array directly:

```php
'image_variation_presets' => [
    'avatar' => [
        'width' => 256,
        'height' => 256,
        'fit' => ImageFit::Crop,
        'format' => ImageFormat::Avif,
        'quality' => 58,
        'enabled' => true,
    ],
    'content' => [
        'max_size' => 1200,
        'fit' => ImageFit::Max,
        'format' => ImageFormat::Webp,
        'quality' => 84,
        'enabled' => true,
    ],
],
```

A preset-specific quality/compression overrides its format profile. Persisted variation labels must start with an alphanumeric character, contain only letters, digits, `_` or `-`, and be at most 30 characters.

Model/slot conversions use the same `ConversionDefinition`:

```php
$conversion
    ->fit(ImageFit::Crop, 640, 360)
    ->format(ImageFormat::Webp)
    ->quality(82)
    ->sharpen(5)
    ->onQueue('priority-media');
```

Terminal uploads may supply `withVariations()` definitions as `ConversionDefinition` instances or preset arrays. Resolution precedence is upload override, model, slot, then global. Upload definitions are normalized into a stable payload and persisted on the media row so replacement and regeneration reproduce them. Deduplicated shared assets accept identical definitions and new labels, but reject conflicts for an existing label. `withoutVariations()` suppresses every automatic dispatch for that terminal upload.

## Queue configuration

```php
'queue' => [
    'enabled' => true,
    'connection' => env('MEDIA_QUEUE_CONNECTION', 'redis'),
    'name' => env('MEDIA_QUEUE', 'media'),
    'jobs' => [
        'generate' => [
            'tries' => 3,
            'timeout' => 60,
            'backoff' => [10, 30, 90],
            'unique_for' => 1800,
        ],
        'dispatch' => [
            'tries' => 3,
            'timeout' => 60,
            'backoff' => [10, 30, 90],
            'unique_for' => 1800,
        ],
        'regenerate' => [
            'tries' => 1,
            'timeout' => 60,
            'backoff' => [60],
        ],
    ],
],
```

When disabled or when the connection is `sync`, normal variation generation runs inline. In production, use Redis, SQS, database, or another durable queue:

```bash
php artisan queue:work redis --queue=media --sleep=1 --tries=3 --timeout=60
```

The connection's `retry_after` must be greater than the longest Media job timeout. For SQS, set the queue visibility timeout accordingly. Laravel recommends worker timeout remain several seconds shorter than the retry/visibility window. Unique jobs require all workers to share an atomic-lock-capable cache.

Jobs and synchronous processing are dispatched only after the real root database commit. One media/revision/label combination has one uniqueness key, but uniqueness is only an optimization: the action-level Redis/cache mutation lock, row reload, and immediate revision comparison are the correctness boundary. A variation is written to a new immutable random object path and persisted with `updateOrCreate`; stale work removes its staged output and exits without a delete/create gap. Superseded objects are removed after commit, while rollback removes only the new object. Retry exhaustion and stale work are logged, and variation rows retain bounded failure context.

## Naming and invalidation

The naming pattern produces the descriptive base of each variation object; generation adds an opaque random suffix before storage. `basename` is the immutable generated source basename, not the untrusted original filename.

Available placeholders:

- `{basename}`
- `{label}`
- `{width}` (actual output width)
- `{height}` (actual output height)
- `{extension}`

Changing content or replacing a source increments the media version/revision. URLs include the asset version for cache invalidation, while source-revision checks prevent stale queued work.

## Production rollout

1. Confirm encoder, queue timing, disk and lock support with `nvl:media:doctor --production --strict`.
2. Deploy workers with the new code and configuration.
3. Preview with `nvl:media:regenerate --dry-run`.
4. Regenerate one preset and disk first.
5. Verify output dimensions, MIME, file size, URL, ETag, and visibility.
6. Regenerate the remaining catalog through the durable queue.
7. Run `nvl:media:reconcile --production --orphans`.

Never change a naming pattern and delete old objects in the same irreversible deployment.

## Official references

- [Spatie Image supported formats](https://spatie.be/docs/image/v3/formats)
- [Spatie Image resizing](https://spatie.be/docs/image/v3/image-manipulations/resizing-images)
- [Laravel queues](https://laravel.com/docs/12.x/queues)
- [Google WebP compression study](https://developers.google.com/speed/webp)
