# NVL Media

Media provides complete services for uploads, associations, private delivery, image variations, multipart uploads, scanning and storage recovery. Requires PHP 8.4 and Laravel 13.

```bash
composer require nvl/media:^7.0
php artisan vendor:publish --tag=nvl-media-config
```

Laravel discovers the provider automatically. The provider publishes its full runtime defaults to `config/nvl-media.php`; the [configuration reference](docs/configuration.md) explains the settings. Configure the host before accepting uploads, then run `php artisan nvl:media:doctor --production --strict`.

To install the packaged agent guidance in the application's `.agents/skills/nvl-media` directory:

```bash
php artisan vendor:publish --tag=nvl-media-skills
```

Review local skill changes before republishing. The skill refers to the installed package's documentation under `vendor/nvl/media/docs`.

Consumers own their data objects, Actions, controllers, routes, resources and frontend types. Services accept explicit actors and native models, files, enums and documented arrays. Services retain validation, authorization, ownership admission, transactions, locks and after-commit cleanup.

```php
use Nvl\Media\Services\MediaReader;

$assets = app(MediaReader::class)->paginate($actor, ['per_page' => 25, 'page' => 1]);
```

Bind the authorization contracts and register model slots with `HasNvlMedia::registerNvlMedia()` before writing media. `SystemActor::Background` requires an explicit policy grant. Optional Spatie Permission support does not bypass native ownership boundaries.

Configure Laravel filesystem disks, their allowlist and delivery policies, a scanner for untrusted files, and queue workers for image processing. Built-in S3 storage requires the optional SDK and adapter documented in [S3](docs/s3.md); model-declared ownership requires the compatible `nvl/ownership:^7.0` runtime. Unowned and local-only installations do not require either feature. Image drivers need their corresponding runtime extension/library. Services return native models and domain results; consumers shape public responses and protect private storage attributes.

Publish `nvl-media-config` when configuration overrides are needed. Choose exactly one migration owner: automatic package migrations with `nvl-media.migrations.enabled=true`, or published `nvl-media-migrations` with automatic loading disabled. Review existing tables before applying migrations.

Both public and private URL defaults use application delivery. Public direct/CDN URLs are an explicit per-disk choice. Private URL reads return an authenticated endpoint; issuing signed application or provider temporary links requires an authorized actor and explicit expiration. See [delivery configuration](docs/configuration.md#asset-delivery).

The package registers no HTTP routes. Consumers implement authenticated management and signed delivery endpoints using the package authorization and storage services. See [HTTP integration](docs/http-api.md).

- [PHP API](docs/php-api.md)
- [HTTP integration](docs/http-api.md)
- [Configuration](docs/configuration.md)
- [Extension contracts and lifecycle events](docs/extending.md)
- [Image variations and queues](docs/images-and-queues.md)
- [S3 and object storage](docs/s3.md)
- [Commands](docs/commands.md)
- [Events](docs/events.md)
- [Upgrading](UPGRADING.md)
- [Security](SECURITY.md)
- [Contributing](CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)

The package ships its [machine-readable consumer API catalog](resources/consumer-api.json) alongside these references. Use the documented native services and focused extension contracts when composing application adapters.
