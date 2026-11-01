# NVL Media events

The source event classes and [machine-readable event catalog](../resources/event-catalog.json) describe the major 7 payloads. Event `schemaVersion` is independent of catalog version. Old `*Event` aliases and model-bearing payloads are retired; use canonical class names in listeners, `Event::fake()` and assertions.

## Publication and transaction timing

Package producers call `Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection)`. The host dispatcher receives the captured event after the actual source connection's outer commit, or immediately when that connection has no active transaction. Callbacks attach to the matching connection and nesting record; rollback of that savepoint or outer transaction discards the corresponding publication. Unrelated connection commits cannot publish them. Missing source transaction records fail before commit.

Host after-commit listeners and queue `after_commit` policies can add their own deferral. Native `Event::dispatch()` is immediate and does not acquire package transaction handling. Host transaction infrastructure and dispatcher bindings remain in place.

Callbacks are not a durable outbox. A process exit after commit can lose delivery, and repeated producer calls can emit repeated observations. No cross-process deduplication, crash durability or exactly-once delivery is promised. Listener errors cannot undo committed database/file ownership.

## Payloads and producers

All classes implement the native `DomainEvent` and `OwnershipQueuedJob` contracts. `schemaVersion(): int` returns the payload version; `ownershipJobEnvelope()` returns the authority captured when the event was constructed. Actor/owner identifiers and envelopes do not grant a listener permission to read private storage.

Association snapshots contain only `media_id`, `associable_type`, `associable_id`, `collection` and nullable `locale`. They exclude arbitrary association metadata. Payloads contain no models, private paths, object bytes, signed URLs or temporary credentials. Constructors accepting arrays do not sanitize arbitrary host-added data recursively.

| Event | Constructor fields in native order | Current package producer |
| --- | --- | --- |
| [MediaAttached](../src/Events/MediaAttached.php) | `string $mediaId`, `array $association`, `int $schemaVersion = 1` | [MediaAttachments::attach()](../src/Services/MediaAttachments.php), on the persisted association connection |
| [MediaDetached](../src/Events/MediaDetached.php) | `string $mediaId`, `array $affectedAssociations`, `int $schemaVersion = 1` | [MediaAttachments::detach()](../src/Services/MediaAttachments.php), on the configured Media connection |
| [MediaMutated](../src/Events/MediaMutated.php) | `string $mediaId`, `string $mutation`, `array $affectedAssociations = []`, `int $schemaVersion = 1` | [MediaLibrary](../src/Services/MediaLibrary.php) and [MediaUploader](../src/Services/MediaUploader.php), on the affected Media connection |
| [MediaUploaded](../src/Events/MediaUploaded.php) | `string $mediaId`, `int $schemaVersion = 1` | [MediaUploader::upload()](../src/Services/MediaUploader.php), when a new row/object is created |
| [MediaCatalogGrantAudited](../src/Events/MediaCatalogGrantAudited.php) | `string $operation`, `string $grantId`, `string $ownerScopeKey`, `string $mediaId`, `int $sourceRevision`, `int $grantRevision`, `int $schemaVersion = 1` | Schema only: current `MediaOwnerCatalog` methods do not emit this event |

`MediaAttached::fromAssociation()` captures the persisted association. Detachment and deletion capture snapshot lists before removing rows. Mutation names currently emitted are `renamed`, `metadata_updated`, `relocated`, `deleted`, `moved` and `file_replaced`. Deletion supplies affected associations; ordinary mutations use the empty list default. Deduplicated upload reuse does not emit a new `MediaUploaded` fact. Do not assume every catalog, tag, variation or scan operation emits an event.

## Consumer listeners

```php
use Illuminate\Support\Facades\Event;
use Nvl\Media\Events\MediaUploaded;

Event::listen(MediaUploaded::class, HandleMediaUploaded::class);
```

Resolve current data through authorized services in the captured ownership context. Preserve the event's source identity and revision when making downstream decisions. Use a durable host outbox if the business workflow requires crash-safe delivery.

Drain old model-bearing queued payloads and restart workers before upgrading. Legacy aliases are not a major 7 compatibility API. See [extension contracts](extending.md), [native PHP services](php-api.md) and [upgrade guidance](../UPGRADING.md).
