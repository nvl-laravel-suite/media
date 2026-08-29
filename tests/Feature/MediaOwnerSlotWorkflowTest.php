<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Actions\GetOwnerMediaSlotAction;
use Nvl\Media\Actions\ReplaceOwnerMediaSlotAction;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\Display\MediaLibraryItem;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaOwnerSlotOperationStatus;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Events\MediaAttached;
use Nvl\Media\Events\MediaDetached;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Services\MediaLibraryItemDataFactory;
use Nvl\Media\Services\MediaOwnerSlotIdempotency;
use Nvl\Media\Support\MediaOwnerSlotOperationClaim;
use Nvl\Media\Tests\Stubs\OwnerSlotWorkflowModel;
use Nvl\Media\Tests\Stubs\TestMediaModel;

function ownerSlotActor(string $identifier = 'actor-1'): MediaActorData
{
    return new MediaActorData(TestMediaModel::class, $identifier);
}

function ownerSlotOwner(string $name = 'Owner'): TestMediaModel
{
    return TestMediaModel::query()->create(['name' => $name]);
}

function ownerSlotMedia(array $overrides = []): Media
{
    return Media::query()->create(array_merge([
        'filename' => 'document.pdf',
        'hash' => hash('sha256', Str::uuid()->toString()).'.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
        'disk' => 'public',
        'folder' => 'documents',
        'is_public' => false,
        'type' => MediaType::DOCUMENT,
        'digest' => hash('sha256', Str::uuid()->toString()),
    ], $overrides));
}

function ownerSlotWorkflowOwner(string $name = 'Workflow owner'): OwnerSlotWorkflowModel
{
    return OwnerSlotWorkflowModel::query()->create(['name' => $name]);
}

function ownerSlotWorkflowActor(string $name = 'Workflow actor'): OwnerSlotWorkflowModel
{
    return OwnerSlotWorkflowModel::query()->create(['name' => $name]);
}

function ownerSlotWorkflowActorData(OwnerSlotWorkflowModel $actor): MediaActorData
{
    return new MediaActorData($actor->getMorphClass(), (string) $actor->getKey());
}

/**
 * @param  Closure(MediaActorData, MediaAbility, ?Media, ?Model): bool  $callback
 */
function useOwnerSlotAuthorization(Closure $callback): void
{
    app()->instance(MediaAuthorization::class, new readonly class($callback) implements MediaAuthorization
    {
        /**
         * @param  Closure(MediaActorData, MediaAbility, ?Media, ?Model): bool  $callback
         */
        public function __construct(private Closure $callback) {}

        public function allows(
            MediaActorData $actor,
            MediaAbility $ability,
            ?Media $media = null,
            ?Model $owner = null,
        ): bool {
            return ($this->callback)($actor, $ability, $media, $owner);
        }
    });
}

function attachOwnerSlotMedia(
    Media $media,
    Model $owner,
    string $collection,
): MediaAssociation {
    return MediaAssociation::query()->create([
        'media_id' => $media->id,
        'associable_type' => $owner->getMorphClass(),
        'associable_id' => (string) $owner->getKey(),
        'collection' => $collection,
        'order' => 0,
        'metadata' => ['slot' => $collection],
    ]);
}

it('installs the owner-slot operation ledger with its portable indexes', function (): void {
    expect(Schema::hasTable(MediaTables::OwnerSlotOperations))->toBeTrue();

    foreach ([
        'id',
        'idempotency_key',
        'actor_type',
        'actor_id',
        'owner_type',
        'owner_id',
        'slot',
        'operation',
        'request_hash',
        'status',
        'result_media_id',
        'result_payload',
        'failure_code',
        'completed_at',
        'failed_at',
        'created_at',
        'updated_at',
    ] as $column) {
        expect(Schema::hasColumn(MediaTables::OwnerSlotOperations, $column))->toBeTrue();
    }

    $indexes = collect(Schema::getIndexes(MediaTables::OwnerSlotOperations))
        ->pluck('name');

    expect($indexes)
        ->toContain('media_owner_slot_idempotency_unique')
        ->toContain('media_owner_slot_owner_slot_idx')
        ->toContain('media_owner_slot_created_idx');
});

it('uses configured owner-slot operation storage', function (): void {
    $connection = (string) config('database.default');

    config([
        'media.owner_slots.idempotency.connection' => $connection,
        'media.owner_slots.idempotency.table' => 'custom_media_owner_slot_operations',
    ]);

    $operation = new MediaOwnerSlotOperation;

    expect($operation->getConnectionName())->toBe($connection)
        ->and($operation->getTable())->toBe('custom_media_owner_slot_operations');

    config(['media.owner_slots.idempotency.table' => 'unsafe-name']);

    expect(fn (): string => (new MediaOwnerSlotOperation)->getTable())
        ->toThrow(InvalidArgumentException::class, 'safe table name');

    config(['media.owner_slots.idempotency.table' => MediaTables::Media]);

    expect(fn (): string => (new MediaOwnerSlotOperation)->getTable())
        ->toThrow(InvalidArgumentException::class, 'must not collide');
});

it('claims, completes, and exactly replays canonical requests', function (): void {
    $service = app(MediaOwnerSlotIdempotency::class);
    $owner = ownerSlotOwner();
    $actor = ownerSlotActor();
    $media = ownerSlotMedia();
    $key = Str::uuid()->toString();

    $claim = $service->begin(
        key: mb_strtoupper($key),
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Replace,
        payload: [
            'metadata' => ['z' => 2, 'a' => 1],
            'media_id' => $media->id,
        ],
    );

    expect($claim->replayed)->toBeFalse()
        ->and($claim->resultMediaId)->toBeNull();

    expect(fn () => $service->begin(
        key: $key,
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Replace,
        payload: [
            'media_id' => $media->id,
            'metadata' => ['a' => 1, 'z' => 2],
        ],
    ))->toThrow(LogicException::class, 'in progress');

    $service->complete($claim, $media->id, ['id' => $media->id]);

    $replay = $service->begin(
        key: $key,
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Replace,
        payload: [
            'media_id' => $media->id,
            'metadata' => ['a' => 1, 'z' => 2],
        ],
    );

    expect($replay->operationId)->toBe($claim->operationId)
        ->and($replay->replayed)->toBeTrue()
        ->and($replay->resultMediaId)->toBe($media->id)
        ->and($replay->resultPayload)->toBe(['id' => $media->id]);

    $operation = MediaOwnerSlotOperation::query()->findOrFail($claim->operationId);

    expect($operation->idempotency_key)->toBe(Str::lower($key))
        ->and($operation->status)->toBe(MediaOwnerSlotOperationStatus::Completed)
        ->and($operation->completed_at)->not->toBeNull();
});

it('rejects invalid keys and reuse for a different payload, owner, or actor', function (): void {
    $service = app(MediaOwnerSlotIdempotency::class);
    $owner = ownerSlotOwner('First owner');
    $otherOwner = ownerSlotOwner('Second owner');
    $actor = ownerSlotActor();
    $key = Str::uuid()->toString();

    expect(fn () => $service->begin(
        key: 'not-a-uuid',
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Replace,
        payload: [],
    ))->toThrow(InvalidArgumentException::class, 'valid UUID');

    $service->begin(
        key: $key,
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Replace,
        payload: ['media_id' => 'first'],
    );

    foreach ([
        [$actor, $owner, ['media_id' => 'second']],
        [$actor, $otherOwner, ['media_id' => 'first']],
        [ownerSlotActor('actor-2'), $owner, ['media_id' => 'first']],
    ] as [$candidateActor, $candidateOwner, $payload]) {
        expect(fn () => $service->begin(
            key: $key,
            actor: $candidateActor,
            owner: $candidateOwner,
            slot: 'document',
            operation: MediaOwnerSlotOperationType::Replace,
            payload: $payload,
        ))->toThrow(LogicException::class, 'different request');
    }

    expect(fn () => $service->begin(
        key: Str::uuid()->toString(),
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Replace,
        payload: ['invalid' => new stdClass],
    ))->toThrow(InvalidArgumentException::class, 'scalar values');
});

it('reclaims failed requests and preserves nullable clear replays', function (): void {
    $service = app(MediaOwnerSlotIdempotency::class);
    $owner = ownerSlotOwner();
    $actor = ownerSlotActor();
    $key = Str::uuid()->toString();
    $claim = $service->begin(
        key: $key,
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Clear,
        payload: [],
    );

    expect(fn () => $service->fail($claim, 'Exception message with spaces'))
        ->toThrow(InvalidArgumentException::class, 'stable failure code');

    $service->fail($claim, 'association_failed');

    $retry = $service->begin(
        key: $key,
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Clear,
        payload: [],
    );

    expect($retry->operationId)->not->toBe($claim->operationId)
        ->and($retry->replayed)->toBeFalse();

    $retriedOperation = MediaOwnerSlotOperation::query()->findOrFail($retry->operationId);

    expect($retriedOperation->status)->toBe(MediaOwnerSlotOperationStatus::Processing)
        ->and($retriedOperation->failure_code)->toBeNull()
        ->and($retriedOperation->failed_at)->toBeNull();

    expect(fn () => $service->complete($claim, null))
        ->toThrow(LogicException::class, 'no longer exists');

    $service->complete($retry, null);

    $replay = $service->begin(
        key: $key,
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Clear,
        payload: [],
    );

    expect($replay->replayed)->toBeTrue()
        ->and($replay->resultMediaId)->toBeNull();

    expect(fn () => $service->fail($retry, 'late_failure'))
        ->toThrow(LogicException::class, 'no longer in progress');
});

it('requires the request hash when transitioning a claim', function (): void {
    $service = app(MediaOwnerSlotIdempotency::class);
    $claim = $service->begin(
        key: Str::uuid()->toString(),
        actor: ownerSlotActor(),
        owner: ownerSlotOwner(),
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Clear,
        payload: [],
    );
    $forged = new MediaOwnerSlotOperationClaim(
        operationId: $claim->operationId,
        requestHash: str_repeat('0', 64),
        replayed: false,
        resultMediaId: null,
    );

    expect(fn () => $service->complete($forged, null))
        ->toThrow(LogicException::class, 'claimed request hash');
});

it('recovers expired processing leases while invalidating the stale claim', function (): void {
    config([
        'media.owner_slots.idempotency.processing_timeout_minutes' => 5,
    ]);

    $service = app(MediaOwnerSlotIdempotency::class);
    $owner = ownerSlotOwner();
    $actor = ownerSlotActor();
    $key = Str::uuid()->toString();
    $claim = $service->begin(
        key: $key,
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Clear,
        payload: [],
    );

    MediaOwnerSlotOperation::query()
        ->whereKey($claim->operationId)
        ->update(['updated_at' => now()->subMinutes(6)]);

    $recovered = $service->begin(
        key: $key,
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Clear,
        payload: [],
    );

    expect($recovered->operationId)->not->toBe($claim->operationId)
        ->and($recovered->replayed)->toBeFalse();

    expect(fn () => $service->complete($claim, null))
        ->toThrow(LogicException::class, 'no longer exists');

    MediaOwnerSlotOperation::query()
        ->whereKey($recovered->operationId)
        ->update(['updated_at' => now()->subMinutes(4)]);

    $service->renew($recovered);

    expect(
        MediaOwnerSlotOperation::query()
            ->findOrFail($recovered->operationId)
            ->updated_at,
    )->toBeGreaterThan(now()->subMinute());
});

it('prunes only expired terminal operations in bounded chunks', function (): void {
    $service = app(MediaOwnerSlotIdempotency::class);
    $owner = ownerSlotOwner();
    $actor = ownerSlotActor();

    $completed = $service->begin(
        Str::uuid()->toString(),
        $actor,
        $owner,
        'document',
        MediaOwnerSlotOperationType::Clear,
        [],
    );
    $service->complete($completed, null);

    $failed = $service->begin(
        Str::uuid()->toString(),
        $actor,
        $owner,
        'document',
        MediaOwnerSlotOperationType::Replace,
        ['media_id' => 'missing'],
    );
    $service->fail($failed, 'media_missing');

    $processing = $service->begin(
        Str::uuid()->toString(),
        $actor,
        $owner,
        'document',
        MediaOwnerSlotOperationType::Copy,
        ['media_id' => 'source'],
    );

    MediaOwnerSlotOperation::query()
        ->whereIn('id', [$completed->operationId, $failed->operationId])
        ->update([
            'completed_at' => now()->subDays(8),
            'failed_at' => now()->subDays(8),
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);
    MediaOwnerSlotOperation::query()
        ->whereKey($processing->operationId)
        ->update([
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);

    config([
        'media.owner_slots.idempotency.retention_days' => 7,
        'media.owner_slots.idempotency.prune_chunk' => 1,
    ]);

    expect($service->prune())->toBe(2)
        ->and(MediaOwnerSlotOperation::query()->find($completed->operationId))->toBeNull()
        ->and(MediaOwnerSlotOperation::query()->find($failed->operationId))->toBeNull()
        ->and(MediaOwnerSlotOperation::query()->find($processing->operationId))->not->toBeNull();

    expect(fn (): int => $service->prune(chunkSize: 1_001))
        ->toThrow(InvalidArgumentException::class, 'no greater than 1000');
});

it('projects the collection from the selected association without changing library defaults', function (): void {
    $firstOwner = ownerSlotOwner('First owner');
    $selectedOwner = ownerSlotOwner('Selected owner');
    $media = ownerSlotMedia();

    MediaAssociation::query()->create([
        'media_id' => $media->id,
        'associable_type' => $firstOwner->getMorphClass(),
        'associable_id' => $firstOwner->getKey(),
        'collection' => 'gallery',
        'order' => 0,
    ]);
    $selected = MediaAssociation::query()->create([
        'media_id' => $media->id,
        'associable_type' => $selectedOwner->getMorphClass(),
        'associable_id' => $selectedOwner->getKey(),
        'collection' => 'document',
        'order' => 0,
    ]);
    $loaded = $media->fresh(['associations', 'translations', 'imageVariations']);

    expect($loaded)->toBeInstanceOf(Media::class);

    $factory = app(MediaLibraryItemDataFactory::class);
    $defaultItem = $factory->fromModel($loaded);
    $selectedItem = $factory->fromAssociation($loaded, $selected);

    expect($defaultItem->collection)->toBe('gallery')
        ->and($selectedItem->collection)->toBe('document')
        ->and($selectedItem->id)->toBe($media->id);

    expect(fn () => $factory->fromAssociation(ownerSlotMedia(), $selected))
        ->toThrow(InvalidArgumentException::class, 'does not belong');

    $unsaved = new MediaAssociation([
        'media_id' => $media->id,
        'collection' => 'document',
    ]);

    expect(fn () => $factory->fromAssociation($loaded, $unsaved))
        ->toThrow(InvalidArgumentException::class, 'must be persisted');
});

it('reads and replaces a registered single-file owner slot with an exact replay', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $media = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $key = Str::uuid()->toString();

    useOwnerSlotAuthorization(
        static fn (
            MediaActorData $candidateActor,
            MediaAbility $ability,
            ?Media $candidateMedia,
            ?Model $candidateOwner,
        ): bool => $candidateActor->id === (string) $actor->getKey()
            && $candidateOwner?->is($owner) === true
            && in_array($ability, [MediaAbility::Associate, MediaAbility::View], true),
    );

    expect(app(GetOwnerMediaSlotAction::class)->execute(
        actor: $actorData,
        owner: $owner,
        slot: 'document',
    ))->toBeNull();

    Event::fake([MediaAttached::class, MediaDetached::class, MediaMutated::class]);

    $result = app(ReplaceOwnerMediaSlotAction::class)->execute(
        actor: $actorData,
        owner: $owner,
        slot: 'document',
        mediaId: mb_strtoupper($media->id),
        idempotencyKey: $key,
    );
    $replay = app(ReplaceOwnerMediaSlotAction::class)->execute(
        actor: $actorData,
        owner: $owner,
        slot: 'document',
        mediaId: $media->id,
        idempotencyKey: $key,
    );
    $read = app(GetOwnerMediaSlotAction::class)->execute(
        actor: $actorData,
        owner: $owner,
        slot: 'document',
    );

    expect($result)->toBeInstanceOf(MediaLibraryItem::class)
        ->and($result->id)->toBe($media->id)
        ->and($result->collection)->toBe('document')
        ->and($replay->toArray())->toBe($result->toArray())
        ->and($read?->toArray())->toBe($result->toArray())
        ->and($owner->fresh()->getFirstMedia('document')?->id)->toBe($media->id);

    $association = MediaAssociation::query()
        ->where('media_id', $media->id)
        ->where('associable_type', $owner->getMorphClass())
        ->where('associable_id', $owner->getKey())
        ->where('collection', 'document')
        ->sole();

    expect($association->metadata)->toBe(['slot' => 'document']);

    Event::assertDispatchedTimes(MediaAttached::class, 1);
    Event::assertNotDispatched(MediaDetached::class);
    Event::assertNotDispatched(MediaMutated::class);
});

it('works without custom authorization for the exact private uploader identity', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $media = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);

    $replaced = app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        ' document ',
        $media->id,
    );
    $read = app(GetOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
    );

    expect($replaced->id)->toBe($media->id)
        ->and($read?->id)->toBe($media->id);
});

it('exactly replays a completed replacement after a later exclusive replacement', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $first = ownerSlotMedia([
        'filename' => 'first.pdf',
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $second = ownerSlotMedia([
        'filename' => 'second.pdf',
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $firstKey = Str::uuid()->toString();

    $firstResult = app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $first->id,
        $firstKey,
    );
    app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $second->id,
        Str::uuid()->toString(),
    );
    $replay = app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $first->id,
        $firstKey,
    );

    expect($replay->toArray())->toBe($firstResult->toArray())
        ->and($owner->fresh()->getFirstMedia('document')?->id)->toBe($second->id)
        ->and(Media::withTrashed()->find($first->id)?->trashed())->toBeTrue();
});

it('persists and replays a valid owner-slot result larger than 65535 bytes', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $tags = array_map(
        static fn (int $index): string => sprintf(
            'tag-%04d-%s',
            $index,
            str_repeat('x', 72),
        ),
        range(1, 1_000),
    );
    $media = ownerSlotMedia([
        'tags' => $tags,
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $key = Str::uuid()->toString();

    $result = app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $media->id,
        $key,
    );
    $replay = app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $media->id,
        $key,
    );

    expect(strlen(json_encode($result->toArray(), JSON_THROW_ON_ERROR)))
        ->toBeGreaterThan(65_535)
        ->and($replay->toArray())->toBe($result->toArray())
        ->and($owner->fresh()->getFirstMedia('document')?->id)->toBe($media->id);
});

it('rejects unknown, unsaved, non-single, and corrupt owner slots', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $media = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    useOwnerSlotAuthorization(static fn (): bool => true);

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'missing',
        $media->id,
    ))->toThrow(InvalidArgumentException::class, 'not registered');

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        new OwnerSlotWorkflowModel,
        'document',
        $media->id,
    ))->toThrow(InvalidArgumentException::class, 'persisted');

    $owner->addMediaSlot('multi');

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'multi',
        $media->id,
    ))->toThrow(InvalidArgumentException::class, 'single-file');

    $longSlot = str_repeat('a', 51);
    $owner->addMediaSlot($longSlot)->singleFile();

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        $longSlot,
        $media->id,
    ))->toThrow(InvalidArgumentException::class, 'at most 50');

    attachOwnerSlotMedia(ownerSlotMedia(), $owner, 'document');
    attachOwnerSlotMedia(ownerSlotMedia(), $owner, 'document');

    expect(fn () => app(GetOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
    ))->toThrow(LogicException::class, 'multiple associations');
});

it('rejects unavailable, deleted, incompatible, oversized, and custom-accepted staged records', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    useOwnerSlotAuthorization(static fn (): bool => true);

    $unavailable = ownerSlotMedia([
        'status' => MediaLifecycleStatus::Quarantined,
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $unavailable->id,
    ))->toThrow(MediaUploadException::class, 'not available');

    $deleted = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $deleted->delete();

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $deleted->id,
    ))->toThrow(MediaUploadException::class, 'deleted');

    $wrongMime = ownerSlotMedia([
        'mime_type' => 'text/plain',
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $wrongMime->id,
    ))->toThrow(FileUnacceptableForCollection::class, 'not accepted');

    $oversized = ownerSlotMedia([
        'size' => 2_049,
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $oversized->id,
    ))->toThrow(FileUnacceptableForCollection::class, 'exceeds maximum');

    $custom = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'custom',
        $custom->id,
    ))->toThrow(FileUnacceptableForCollection::class, 'custom');

    $public = ownerSlotMedia([
        'is_public' => true,
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $public->id,
    ))->toThrow(FileUnacceptableForCollection::class, 'visibility');
});

it('enforces owner authorization and exact uploader identity for unassociated staging media', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $media = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);

    useOwnerSlotAuthorization(static fn (): bool => false);

    expect(fn () => app(GetOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
    ))->toThrow(AuthorizationException::class, 'view');

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $media->id,
    ))->toThrow(AuthorizationException::class, 'associate');

    useOwnerSlotAuthorization(
        static fn (
            MediaActorData $candidateActor,
            MediaAbility $ability,
        ): bool => $ability === MediaAbility::Associate,
    );

    $foreign = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => TestMediaModel::class,
    ]);

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $foreign->id,
    ))->toThrow(AuthorizationException::class, 'staged media');

    $anonymous = new MediaActorData(null, null);
    $anonymousMedia = ownerSlotMedia();

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $anonymous,
        $owner,
        'document',
        $anonymousMedia->id,
    ))->toThrow(AuthorizationException::class, 'staged media');

    $fakeSystem = new MediaActorData('system', null);

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $fakeSystem,
        $owner,
        'document',
        $anonymousMedia->id,
    ))->toThrow(AuthorizationException::class, 'staged media');

    $trustedOwner = ownerSlotWorkflowOwner('Trusted system owner');
    $trusted = app(ReplaceOwnerMediaSlotAction::class)->execute(
        MediaActorData::system(),
        $trustedOwner,
        'document',
        $anonymousMedia->id,
    );

    expect($trusted->id)->toBe($anonymousMedia->id);
});

it('adopts only actor-owned staging associations unless manage-staging is granted', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $foreignActor = ownerSlotWorkflowActor('Foreign actor');
    $actorData = ownerSlotWorkflowActorData($actor);
    $ownStaged = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $ownStagingAssociation = attachOwnerSlotMedia($ownStaged, $actor, 'uploads');

    useOwnerSlotAuthorization(
        static fn (
            MediaActorData $candidateActor,
            MediaAbility $ability,
        ): bool => $ability === MediaAbility::Associate,
    );

    app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $ownStaged->id,
    );

    expect(MediaAssociation::query()->find($ownStagingAssociation->id))->toBeNull()
        ->and($owner->fresh()->getFirstMedia('document')?->id)->toBe($ownStaged->id);

    $foreignStaged = ownerSlotMedia([
        'uploaded_by' => (string) $foreignActor->getKey(),
        'uploaded_by_type' => $foreignActor->getMorphClass(),
    ]);
    $foreignStagingAssociation = attachOwnerSlotMedia(
        $foreignStaged,
        $foreignActor,
        'uploads',
    );

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $foreignStaged->id,
    ))->toThrow(AuthorizationException::class, 'manage staging');

    useOwnerSlotAuthorization(
        static fn (
            MediaActorData $candidateActor,
            MediaAbility $ability,
        ): bool => in_array(
            $ability,
            [MediaAbility::Associate, MediaAbility::ManageStaging],
            true,
        ),
    );

    app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $foreignStaged->id,
    );

    expect(MediaAssociation::query()->find($foreignStagingAssociation->id))->toBeNull()
        ->and($owner->fresh()->getFirstMedia('document')?->id)->toBe($foreignStaged->id);

    $domainAttached = ownerSlotMedia([
        'uploaded_by' => (string) $foreignActor->getKey(),
        'uploaded_by_type' => $foreignActor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($domainAttached, $otherOwner = ownerSlotWorkflowOwner(), 'document');

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $domainAttached->id,
    ))->toThrow(AuthorizationException::class, 'non-staging owners');
});

it('reuses an authorized public shared asset without removing its associations', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $libraryOwner = ownerSlotWorkflowOwner('Library owner');
    $actorData = ownerSlotWorkflowActorData($actor);
    $media = ownerSlotMedia(['is_public' => true]);
    $libraryAssociation = attachOwnerSlotMedia($media, $libraryOwner, 'library');

    useOwnerSlotAuthorization(
        static fn (
            MediaActorData $candidateActor,
            MediaAbility $ability,
        ): bool => $ability === MediaAbility::Associate,
    );

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'library',
        $media->id,
    ))->toThrow(AuthorizationException::class, 'reuse');

    useOwnerSlotAuthorization(
        static fn (
            MediaActorData $candidateActor,
            MediaAbility $ability,
        ): bool => in_array(
            $ability,
            [MediaAbility::Associate, MediaAbility::Reuse],
            true,
        ),
    );

    $result = app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'library',
        $media->id,
    );

    expect($result->id)->toBe($media->id)
        ->and(MediaAssociation::query()->find($libraryAssociation->id))->not->toBeNull()
        ->and($media->fresh()->associations()->count())->toBe(2);
});

it('keeps same-media replacement quiet and applies deterministic lifecycle semantics', function (): void {
    Storage::fake('public');

    $owner = ownerSlotWorkflowOwner();
    $otherOwner = ownerSlotWorkflowOwner('Other owner');
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    useOwnerSlotAuthorization(static fn (): bool => true);

    $current = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    Storage::disk('public')->put($current->buildPath(), 'current');
    attachOwnerSlotMedia($current, $owner, 'document');

    Event::fake([MediaAttached::class, MediaDetached::class, MediaMutated::class]);

    $same = app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $current->id,
    );

    expect($same->id)->toBe($current->id);
    Event::assertNothingDispatched();

    $replacement = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    DB::transaction(function () use (
        $actorData,
        $current,
        $owner,
        $replacement,
    ): void {
        app(ReplaceOwnerMediaSlotAction::class)->execute(
            $actorData,
            $owner,
            'document',
            $replacement->id,
        );

        expect(Storage::disk('public')->exists($current->buildPath()))->toBeTrue();
    });

    expect(Media::query()->find($current->id))->toBeNull()
        ->and(Media::withTrashed()->find($current->id)?->trashed())->toBeTrue()
        ->and(Storage::disk('public')->exists($current->buildPath()))->toBeFalse()
        ->and($owner->fresh()->getFirstMedia('document')?->id)->toBe($replacement->id);

    Event::assertDispatchedTimes(MediaAttached::class, 1);
    Event::assertDispatchedTimes(MediaDetached::class, 1);
    Event::assertDispatchedTimes(MediaMutated::class, 1);

    expect(array_keys(Event::dispatchedEvents()))->toBe([
        MediaAttached::class,
        MediaDetached::class,
        MediaMutated::class,
    ]);

    $sharedCurrent = ownerSlotMedia(['is_public' => true]);
    attachOwnerSlotMedia($sharedCurrent, $owner, 'library');
    attachOwnerSlotMedia($sharedCurrent, $otherOwner, 'library');
    $sharedReplacement = ownerSlotMedia(['is_public' => true]);

    app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'library',
        $sharedReplacement->id,
    );

    expect(Media::query()->find($sharedCurrent->id))->not->toBeNull()
        ->and($sharedCurrent->fresh()->associations()->count())->toBe(1);
});

it('preserves an exclusive predecessor that remains associated elsewhere', function (): void {
    $owner = ownerSlotWorkflowOwner();
    $otherOwner = ownerSlotWorkflowOwner('Other owner');
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    useOwnerSlotAuthorization(static fn (): bool => true);

    $current = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($current, $owner, 'document');
    attachOwnerSlotMedia($current, $otherOwner, 'document');
    $replacement = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);

    app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $replacement->id,
    );

    expect(Media::query()->find($current->id))->not->toBeNull()
        ->and($current->fresh()->associations()->count())->toBe(1);
});

it('rolls back the slot transition and records a retryable failure', function (): void {
    Storage::fake('public');

    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    useOwnerSlotAuthorization(static fn (): bool => true);

    $current = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($current, $owner, 'document');
    $replacement = ownerSlotMedia([
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    Storage::disk('public')->put($current->buildPath(), 'current');
    Storage::disk('public')->put($replacement->buildPath(), 'replacement');
    $key = Str::uuid()->toString();

    Event::fake([MediaAttached::class, MediaDetached::class, MediaMutated::class]);

    app()->instance(DetachMediaContract::class, new class implements DetachMediaContract
    {
        public function execute(
            Media|string $media,
            Model $model,
            ?string $collection = null,
        ): int {
            throw new RuntimeException('Injected detach failure.');
        }
    });

    expect(fn () => app(ReplaceOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $replacement->id,
        $key,
    ))->toThrow(RuntimeException::class, 'Injected detach failure');

    expect($owner->fresh()->getFirstMedia('document')?->id)->toBe($current->id)
        ->and($replacement->fresh()->associations()->count())->toBe(0)
        ->and(Storage::disk('public')->exists($current->buildPath()))->toBeTrue()
        ->and(Storage::disk('public')->exists($replacement->buildPath()))->toBeTrue();

    $operation = MediaOwnerSlotOperation::query()
        ->where('idempotency_key', $key)
        ->sole();

    expect($operation->status)->toBe(MediaOwnerSlotOperationStatus::Failed)
        ->and($operation->failure_code)->toBe('replace_failed');

    Event::assertNothingDispatched();
});
