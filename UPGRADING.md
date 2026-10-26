# NVL Light migration

This branch targets PHP 8.4 and Laravel 13 and intentionally removes the previous DTO, Action, controller, route, facade and fluent-builder API. Consumers own their application adapters. Replace those calls with the complete [native services](docs/php-api.md); keep service authorization, validation, ownership and lifecycle guarantees.

The deprecated Tenancy bridge and its configuration have been retired. Use declared native ownership resources and explicit admitted contexts. Unowned installations remain supported. Existing tenant-named physical columns or historical facts are not an automatic schema-renaming instruction.

Bind host authorization and batch SQL contracts explicitly. Background actors require policy grants. Register slots through `HasNvlMedia` and `MediaRegistration`. Return models through consumer-owned resources and keep private storage/scanner fields out of public responses.

Implement your own [HTTP adapters](docs/http-api.md), management middleware and signed asset endpoints. Update configured delivery route names to those consumer routes. Preserve byte identity, private access checks, scanning, idempotency, transaction callbacks and multipart recovery.

Review [storage configuration](docs/configuration.md), [extension contracts](docs/extending.md), [S3](docs/s3.md) and [operational commands](docs/commands.md). Existing files do not move when prefixes or visibility defaults change. Choose one migration owner and review populated tables before applying schema changes.
