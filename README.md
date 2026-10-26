# NVL Media

Media provides complete services for uploads, associations, private delivery, image variations, multipart uploads, scanning and storage recovery. Requires PHP 8.4 and Laravel 13.

Consumers own their data objects, Actions, controllers, routes, resources and frontend types. Services accept explicit actors and native models, files, enums and documented arrays. Services retain validation, authorization, ownership admission, transactions, locks and after-commit cleanup.

```php
use Nvl\Media\Services\MediaReader;

$assets = app(MediaReader::class)->paginate($actor, ['perPage' => 25, 'page' => 1]);
```

Bind the authorization contracts and register model slots with `HasNvlMedia::registerNvlMedia()` before writing media. `SystemActor::Background` requires an explicit policy grant. Optional Spatie Permission support does not bypass native ownership boundaries.

Configure Laravel filesystem disks, their allowlist, a scanner for untrusted files, and queue workers for image processing. S3 multipart and image drivers need their documented optional dependencies. Services return native models and domain results; consumers shape public responses and protect private storage attributes.

Publish `nvl-media-config` when configuration overrides are needed. Choose exactly one migration owner: automatic package migrations with `nvl-media.migrations.enabled=true`, or published `nvl-media-migrations` with automatic loading disabled. Review existing tables before applying migrations.

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
