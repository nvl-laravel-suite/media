# Consumer HTTP integration

NVL Media registers no HTTP routes and ships no controllers, requests, response resources or transport DTOs. Consumers implement the transport around the [native services](php-api.md).

A management controller extracts the authenticated actor and validated scalar, file or model inputs, calls the appropriate service, then projects an explicit response. Keep domain validation, ownership admission, authorization, transaction boundaries, locking, idempotency and storage cleanup inside services. Do not implement a second persistence path in the controller.

Consumers own authentication middleware, CSRF protection, throttling, CORS, request size limits and their error/status mapping. Authorize each public response and expose only reviewed model fields. Do not serialize private storage paths, uploader facts or scanner attestations automatically.

Asset delivery endpoints are consumer-owned named routes. Match the route names configured under `nvl-media.assets`, validate signed private links and allowed parameters, and use the existing asset authorization, URL, header and path services. Retain safe content disposition, MIME, cache and conditional-response headers. A private disk name or a signature alone does not authorize a different owner to read an asset.

Multipart endpoints call `MediaMultipart` with an explicit actor and service-validated arrays. Initiation produces a server-issued upload identity. Signing, completion and abort retain that identity, declared file bounds, provider checks, digest verification and scan requirements. Consumers cannot attest scan results through ordinary upload request metadata.

Consumer Actions and data objects may wrap these services; they remain outside the package. See [extension contracts](extending.md), [configuration](configuration.md) and [S3](s3.md).
