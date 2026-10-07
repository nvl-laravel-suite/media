# NVL media events

This document describes the implemented source behavior. Acceptance is exercised by the owning package suites and Core committed-event regression tests; current release execution evidence is tracked in consumer-readiness.md. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Media/grant and morph owner identities, revisions and association snapshots only. No model, private path, object bytes, temporary credentials or signed URL. Tenant grants expose persisted ownership identifiers.

Existing changed-state/association/grant guards govern publication. File effect commit marking, prior-object cleanup and variation scheduling retain their ordering before publication; listener errors after commit cannot undo committed file ownership.

MediaAttached::fromAssociation snapshots the persisted association; deletion/detachment use captured association arrays because the rows may no longer be queryable at publication.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [MediaAttached](#mediaattached) | 1 | Generic media association persisted. |
| [MediaCatalogGrantAudited](#mediacataloggrantaudited) | 1 | Tenant catalog grant changed. |
| [MediaDetached](#mediadetached) | 1 | Generic association snapshots removed. |
| [MediaMutated](#mediamutated) | 1 | Media public state changed. |
| [MediaUploaded](#mediauploaded) | 1 | Media row and physical object created. |

### MediaAttached

`Nvl\Media\Events\MediaAttached` · [source](../src/Events/MediaAttached.php) · event schema `1`.

Generic media association persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$mediaId` | `string` | public | `required` | `string` |
| `$association` | `array` | public | `required` | `array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string\|null}` |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$mediaId` | `string` | `string` |
| `$association` | `array` | `array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string\|null}` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/AttachMediaAction.php](../src/Actions/AttachMediaAction.php) | `$association->getConnection()` |

### MediaCatalogGrantAudited

`Nvl\Media\Events\MediaCatalogGrantAudited` · [source](../src/Events/MediaCatalogGrantAudited.php) · event schema `1`.

Tenant catalog grant changed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$operation` | `string` | public | `required` | — |
| `$grantId` | `string` | public | `required` | — |
| `$tenantId` | `string` | public | `required` | — |
| `$mediaId` | `string` | public | `required` | — |
| `$sourceRevision` | `int` | public | `required` | — |
| `$grantRevision` | `int` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$operation` | `string` | — |
| `$grantId` | `string` | — |
| `$tenantId` | `string` | — |
| `$mediaId` | `string` | — |
| `$sourceRevision` | `int` | — |
| `$grantRevision` | `int` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/GrantMediaToTenantAction.php](../src/Actions/GrantMediaToTenantAction.php) | `$existing->getConnection()` |
| [Actions/GrantMediaToTenantAction.php](../src/Actions/GrantMediaToTenantAction.php) | `$grant->getConnection()` |
| [Actions/RevokeMediaTenantGrantAction.php](../src/Actions/RevokeMediaTenantGrantAction.php) | `$grant->getConnection()` |

### MediaDetached

`Nvl\Media\Events\MediaDetached` · [source](../src/Events/MediaDetached.php) · event schema `1`.

Generic association snapshots removed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$mediaId` | `string` | public | `required` | `string` |
| `$affectedAssociations` | `array` | public | `required` | `array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string\|null}>` |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$mediaId` | `string` | `string` |
| `$affectedAssociations` | `array` | `array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string\|null}>` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/DetachMediaAction.php](../src/Actions/DetachMediaAction.php) | `(new Media)->getConnection()` |

### MediaMutated

`Nvl\Media\Events\MediaMutated` · [source](../src/Events/MediaMutated.php) · event schema `1`.

Media public state changed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$mediaId` | `string` | public | `required` | `string` |
| `$mutation` | `string` | public | `required` | `string` |
| `$affectedAssociations` | `array` | public | `[]` | `array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string\|null}>` |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$mediaId` | `string` | `string` |
| `$mutation` | `string` | `string` |
| `$affectedAssociations` | `array` | `array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string\|null}>` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/BulkMoveMediaAction.php](../src/Actions/BulkMoveMediaAction.php) | `(new Media)->getConnection()` |
| [Actions/DeleteMediaAction.php](../src/Actions/DeleteMediaAction.php) | `$lockedMedia->getConnection()` |
| [Actions/RelocateMediaAction.php](../src/Actions/RelocateMediaAction.php) | `$locked->getConnection()` |
| [Actions/RenameMediaAction.php](../src/Actions/RenameMediaAction.php) | `$media->getConnection()` |
| [Actions/ReplaceMediaFileAction.php](../src/Actions/ReplaceMediaFileAction.php) | `$lockedMedia->getConnection()` |
| [Actions/UpdateMediaMetadataAction.php](../src/Actions/UpdateMediaMetadataAction.php) | `$media->getConnection()` |

### MediaUploaded

`Nvl\Media\Events\MediaUploaded` · [source](../src/Events/MediaUploaded.php) · event schema `1`.

Media row and physical object created.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$mediaId` | `string` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$mediaId` | `string` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/UploadMediaAction.php](../src/Actions/UploadMediaAction.php) | `$created->getConnection()` |

Deprecated alias: `Nvl\Media\Events\MediaUploadedEvent` ([shim](../src/Events/MediaUploadedEvent.php)). It is the same canonical class with this constructor.

## Major 5 listener and queue migration

Legacy names are deprecated for major 5 and removed no earlier than major 6. class_alias preserves imports, instanceof, listener type hints and the new versioned constructor, not the former model-bearing API.

The native Laravel exact-listener bridge reads getRawListeners at delivery time and prepares legacy listeners through makeListener; strict-identical canonical registrations are skipped. Late registration, subscribers, cached discovery and queued listener preparation use the native dispatcher seams covered by Core’s committed-event and native host transaction regressions.

One canonical object is emitted once. Native wildcard/interface listeners see the canonical event once; suffix-specific *Event wildcards must migrate. The bridge does not redispatch a legacy string.

Drain old queued model-bearing payloads and restart workers before upgrade. New serialized alias instances restore the canonical versioned class without model restoration.

Event fakes/filters and assertions use canonical class names. EventAliases::canonicalName() helps migrate old names; old fake filters are not automatically rewritten.

Host custom dispatchers are retained. Explicit EventAliases::listen($event, $listener) maps through the canonical adapter; absent native bridging/host adapter, Doctor reports the deprecated-name limitation.

```php
$aliases = app(\Nvl\Support\Events\EventAliases::class);
$canonical = $aliases->canonicalName($legacyClass);
$aliases->listen($canonical, $listener);
```

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.

## Consumer event assertions

Use the canonical event class listed in the catalog for `Event::fake([...])` and `Event::assertDispatched(...)`. Laravel fake filters compare the emitted class name; an old alias import does not rename that canonical object. Legacy exact listeners are bridged at delivery time through Laravel’s native dispatcher. Keep compatibility listener tests on their exact legacy name, and migrate suffix-specific wildcards to canonical names.

