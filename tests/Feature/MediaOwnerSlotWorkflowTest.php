<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaOwnerSlotOperationStatus;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Services\MediaLibraryItemDataFactory;
use Nvl\Media\Services\MediaOwnerSlotIdempotency;
use Nvl\Media\Support\MediaOwnerSlotOperationClaim;
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

    $service->complete($claim, $media->id);

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
        ->and($replay->resultMediaId)->toBe($media->id);

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
