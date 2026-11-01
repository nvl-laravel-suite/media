# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/media/security/advisories/new). Public issues must not contain undisclosed vulnerability details.

The current major 7 source requires PHP `^8.4`, Laravel `^13.0`, and compatible major 7 NVL peers. Built-in S3 storage and model-declared ownership have [optional runtime requirements](docs/s3.md). A prepared source tree or passing local test suite does not establish release certification for every database, storage provider or image-driver build.

Include visibility, delivery policy, storage driver, consumer route, signature behavior, scanner result, range request and impact without attaching malicious production files.

Production deployments should require a real scanner, verify checksums server-side, and authorize every private operation. Authenticated application URLs require recipient authorization on each request; signed application and provider temporary URLs are bearer capabilities until expiration. Keep private storage outside publicly exposed buckets/prefixes. Relocation and policy changes cannot recall downloaded bytes or copies cached by a public CDN.

Do not expose private paths, disks, checksums, uploader identities or quarantine details in consumer responses. Preserve the [HTTP delivery checks](docs/http-api.md) and [transaction and cleanup limits](docs/php-api.md#storage-and-operation-guarantees).
