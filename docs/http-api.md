# Consumer HTTP integration

NVL Media registers no HTTP routes and ships no controllers, requests, response resources or transport DTOs. Consumers implement the transport around the [native services](php-api.md).

A management controller extracts the authenticated actor and validated scalar, file or model inputs, calls the appropriate service, then projects an explicit response. Keep domain validation, ownership admission, authorization, transaction boundaries, locking, idempotency and storage cleanup inside services. Do not implement a second persistence path in the controller.

Consumers own authentication middleware, CSRF protection, throttling, CORS, request size limits and their error/status mapping. Authorize each public response and expose only reviewed model fields. Do not serialize private storage paths, uploader facts or scanner attestations automatically.

Asset delivery endpoints are consumer-owned named routes. Match the route names configured under `nvl-media.assets` and use per-disk `nvl-media.delivery` policies. Public application routes stream/proxy bytes when the storage address must stay hidden; redirects disclose it. Private authenticated routes must authenticate before binding and check the actual actor's Download permission on each request. URL reads do not implicitly mint private capabilities.

The URL builders require a `media` route parameter. The signed private route also receives `owner` (the persisted uploader identity or configured fallback); it signs the current `revision` and, for owned assets, the canonical `scope`. Treat those values as bound capability facts, not a recipient login requirement. Route registration and middleware remain application responsibilities.

Resolve server-side storage metadata with `MediaAssetService::resolve($media, $variation, $mode)` after the endpoint's authentication/signature checks. Use `MediaDeliveryMode::Application` for public/authenticated endpoints and `MediaDeliveryMode::SignedApplication` for signed endpoints. The helper reloads the canonical record and variations from the primary connection, rejects forged/stale storage facts and enforces current visibility/mode policy. It does not authenticate the request. Its disk/path result stays server-side.

Issue signed application links only through the actor-authorized `MediaUrlResolver::signedUrl()`. Validate signed private links before binding, then require current availability, the signed revision, and canonical owner/scope. Valid scoped signatures are bearer capabilities until expiry; the recipient need not be the issuing actor or uploader. Use an authenticated route when access requires a recipient login. Provider temporary URLs require an authorized actor and explicit expiration in `temporaryUrl()` issuance, and are not a substitute for route masking or early revocation. Disabled visibility/mode policies are rechecked by application resolution, even for previously issued URLs. Provider URLs and public CDN copies retain their external lifetime/cache limits.

Reject unsupported query parameters. Never discard an explicitly requested variation and grant the original instead. Retain safe content disposition, MIME, cache, conditional-response and byte-range headers. Private local binary responses must explicitly preserve private/no-store headers after constructing the response, since framework file-response defaults can change cache visibility. A private disk name does not enforce provider bucket privacy or canonical ownership.

Multipart endpoints call `MediaMultipart` with an explicit actor and service-validated arrays. Initiation produces a server-issued upload identity. Signing, completion and abort retain that identity, declared file bounds, provider checks, digest verification and scan requirements. Consumers cannot attest scan results through ordinary upload request metadata.

## Delivery response hardening

Set `X-Content-Type-Options: nosniff` on every byte response and use the verified MIME type from the resolved asset. Default untrusted SVG, HTML and other active document formats to `Content-Disposition: attachment`. If the product deliberately renders an active document inline, isolate it on a cookieless origin and apply a response `Content-Security-Policy: sandbox; default-src 'none'`; do not grant script or same-origin sandbox permissions. Configure equivalent headers on provider/CDN delivery, since application response headers cannot protect a direct storage URL.

The SVG scanner validates uploads and preserves original bytes; it is not a general sanitizer or permission to serve arbitrary SVG inline from the application origin. Enabled scanning rejects DTDs, processing instructions, XML Base overrides, obfuscated/external CSS and nested SVG data URLs. XLink references are checked by expanded attribute name, independent of the chosen prefix. Re-export design-tool SVGs without these features before upload. Ordinary XML declarations, local paint references and supported raster data URLs remain usable. Keep scanning enabled and retain the delivery controls even after a scan succeeds.

Consumer Actions and data objects may wrap these services; they remain outside the package. See [extension contracts](extending.md), [configuration](configuration.md) and [S3](s3.md).
