# Extension contracts and lifecycle events

Native services own orchestration. Replace focused host boundaries through container bindings; preserve authorization, owner admission, transactions, locking, source revisions, scan attestations and cleanup guarantees.

Authorization receives an explicit native actor; background identities require explicit grants. Batch policies express the complete visibility predicate in SQL before hydration and receive the admitted owner set. Register actual model slots through `HasNvlMedia` and `MediaRegistration`.

Scanners return validated native results. Multipart gateways retain provider upload identity, bounded parts and recovery. Remote ingestion retains DNS/connected-IP checks and byte/time limits. Search adapters must retain native ownership and authorization predicates.

## Contracts

- `HasNvlMedia`: [HasNvlMedia](../src/Contracts/HasNvlMedia.php).
- `MediaAuthorization`: [MediaAuthorization](../src/Contracts/MediaAuthorization.php).
- `MediaBatchAuthorization`: [MediaBatchAuthorization](../src/Contracts/MediaBatchAuthorization.php).
- `MediaCatalogAccess`: [MediaCatalogAccess](../src/Contracts/MediaCatalogAccess.php).
- `MediaCatalogImport`: [MediaCatalogImport](../src/Contracts/MediaCatalogImport.php).
- `MediaContentScanner`: [MediaContentScanner](../src/Contracts/MediaContentScanner.php).
- `MediaHostResolver`: [MediaHostResolver](../src/Contracts/MediaHostResolver.php).
- `MediaSearchDriver`: [MediaSearchDriver](../src/Contracts/MediaSearchDriver.php).
- `MultipartUploadGateway`: [MultipartUploadGateway](../src/Contracts/MultipartUploadGateway.php).
- `RecoverableMultipartUploadGateway`: [RecoverableMultipartUploadGateway](../src/Contracts/RecoverableMultipartUploadGateway.php).

## Lifecycle events

Events carry committed domain facts. Publish side effects after the real outer commit and preserve rollback behavior. Consumers own display copy, notification delivery and response projections.

- `MediaAttached`: [MediaAttached](../src/Events/MediaAttached.php).
- `MediaCatalogGrantAudited`: [MediaCatalogGrantAudited](../src/Events/MediaCatalogGrantAudited.php).
- `MediaDetached`: [MediaDetached](../src/Events/MediaDetached.php).
- `MediaMutated`: [MediaMutated](../src/Events/MediaMutated.php).
- `MediaUploaded`: [MediaUploaded](../src/Events/MediaUploaded.php).
- `MediaUploadedEvent`: [MediaUploadedEvent](../src/Events/MediaUploadedEvent.php).

See [PHP API](php-api.md), [event semantics](events.md), [queues](images-and-queues.md) and [S3](s3.md).
