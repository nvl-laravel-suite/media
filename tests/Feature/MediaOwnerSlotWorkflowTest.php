<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Actions\ClearOwnerMediaSlotAction;
use Nvl\Media\Actions\CopyOwnerMediaSlotAction;
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
use Nvl\Media\Services\MediaStagingPolicy;
use Nvl\Media\Services\MediaTemporaryFileRegistry;
use Nvl\Media\Slots\MediaSlot;
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

function ownerSlotStoredMedia(string $contents, array $overrides = []): Media
{
    $media = ownerSlotMedia(array_merge([
        'size' => strlen($contents),
        'digest' => hash('sha256', $contents),
    ], $overrides));
    Storage::disk($media->disk)->put($media->buildPath(), $contents);

    return $media;
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

function useOwnerSlotLedger(string $connection, string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);

    if (! is_string($path)) {
        throw new RuntimeException('Unable to create the owner-slot ledger database.');
    }

    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'media.owner_slots.idempotency.connection' => $connection,
        'media.owner_slots.idempotency.processing_timeout_minutes' => 5,
    ]);
    $migration = require __DIR__.'/../../database/migrations/2026_08_28_000000_create_media_owner_slot_operations_table.php';
    $migration->up();

    return $path;
}

function releaseOwnerSlotLedger(string $connection, string $path): void
{
    DB::purge($connection);
    unlink($path);
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

it('rejects deleted and unavailable media before staging cleanup', function (): void {
    $policy = app(MediaStagingPolicy::class);
    $slot = (new MediaSlot('document'))->acceptsMimeTypes(['application/pdf']);
    $deleted = ownerSlotMedia();
    $deleted->delete();

    expect(fn () => $policy->assertFitsSlot($deleted, $slot))
        ->toThrow(MediaUploadException::class, 'is deleted and cannot be associated');

    $unavailable = ownerSlotMedia([
        'status' => MediaLifecycleStatus::Quarantined,
    ]);

    expect(fn () => $policy->assertFitsSlot($unavailable, $slot))
        ->toThrow(MediaUploadException::class, 'is not available for association');
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
    $checkpointMediaId = Str::uuid()->toString();

    $service->checkpoint($claim, $checkpointMediaId);

    MediaOwnerSlotOperation::query()
        ->whereKey($claim->operationId)
        ->update(['updated_at' => now()->subHour()]);

    $recovered = $service->begin(
        key: $key,
        actor: $actor,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Clear,
        payload: [],
    );

    expect($recovered->operationId)->not->toBe($claim->operationId)
        ->and($recovered->replayed)->toBeFalse()
        ->and($recovered->resultMediaId)->toBe($checkpointMediaId);

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

it('clears empty, shared, and exclusive owner slots with exact replay', function (): void {
    Storage::fake('public');

    $owner = ownerSlotWorkflowOwner();
    $otherOwner = ownerSlotWorkflowOwner('Other owner');
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    useOwnerSlotAuthorization(static fn (): bool => false);

    expect(fn () => app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
    ))->toThrow(AuthorizationException::class, 'associate');

    useOwnerSlotAuthorization(static fn (): bool => true);

    $emptyKey = Str::uuid()->toString();
    app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $emptyKey,
    );
    app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $emptyKey,
    );

    $emptyOperation = MediaOwnerSlotOperation::query()
        ->where('idempotency_key', $emptyKey)
        ->sole();

    expect($emptyOperation->status)->toBe(MediaOwnerSlotOperationStatus::Completed)
        ->and($emptyOperation->result_media_id)->toBeNull()
        ->and($owner->fresh()->getFirstMedia('document'))->toBeNull();

    useOwnerSlotAuthorization(static fn (): bool => false);
    $unauthorizedKey = Str::uuid()->toString();

    expect(fn () => app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $emptyKey,
    ))->toThrow(AuthorizationException::class, 'associate');

    expect(fn () => app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $unauthorizedKey,
    ))->toThrow(AuthorizationException::class, 'associate');

    expect(MediaOwnerSlotOperation::query()
        ->where('idempotency_key', $unauthorizedKey)
        ->exists())->toBeFalse();

    useOwnerSlotAuthorization(static fn (): bool => true);

    $shared = ownerSlotStoredMedia('%PDF-1.4 shared', ['is_public' => true]);
    attachOwnerSlotMedia($shared, $owner, 'library');
    attachOwnerSlotMedia($shared, $otherOwner, 'library');

    app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'library',
    );

    expect($shared->fresh()->associations()->count())->toBe(1)
        ->and(Media::query()->find($shared->id))->not->toBeNull()
        ->and(Storage::disk('public')->exists($shared->buildPath()))->toBeTrue();

    $exclusive = ownerSlotStoredMedia('%PDF-1.4 exclusive', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($exclusive, $owner, 'document');
    $exclusiveKey = Str::uuid()->toString();

    app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $exclusiveKey,
    );
    app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $exclusiveKey,
    );

    expect(Media::query()->find($exclusive->id))->toBeNull()
        ->and(Media::withTrashed()->find($exclusive->id)?->trashed())->toBeTrue()
        ->and(Storage::disk('public')->exists($exclusive->buildPath()))->toBeFalse()
        ->and($owner->fresh()->getFirstMedia('document'))->toBeNull();
});

it('replays a clear against its durable authorization subject', function (): void {
    Storage::fake('public');

    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $media = ownerSlotStoredMedia('%PDF-1.4 durable clear subject', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $association = attachOwnerSlotMedia($media, $owner, 'document');
    $key = Str::uuid()->toString();

    app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $key,
    );
    app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $key,
    );

    $operation = MediaOwnerSlotOperation::query()
        ->where('idempotency_key', $key)
        ->sole();

    expect($operation->result_media_id)->toBeNull()
        ->and($operation->result_payload)->toEqual([
            'authorization_association_id' => $association->id,
            'authorization_media_id' => $media->id,
        ]);

    $otherActor = ownerSlotWorkflowActor('Other clear actor');

    expect(fn () => app(ClearOwnerMediaSlotAction::class)->execute(
        ownerSlotWorkflowActorData($otherActor),
        $owner,
        'document',
        $key,
    ))->toThrow(AuthorizationException::class, 'associate');

    useOwnerSlotAuthorization(static fn (): bool => false);

    expect(fn () => app(ClearOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $key,
    ))->toThrow(AuthorizationException::class, 'associate');
});

it('rolls back clear when same-connection idempotency completion fails', function (): void {
    Storage::fake('public');

    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $media = ownerSlotStoredMedia('%PDF-1.4 clear completion rollback', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($media, $owner, 'document');
    $key = Str::uuid()->toString();

    MediaOwnerSlotOperation::updating(
        static function (MediaOwnerSlotOperation $operation): void {
            if ($operation->status === MediaOwnerSlotOperationStatus::Completed) {
                throw new RuntimeException('Injected clear completion failure.');
            }
        },
    );

    try {
        expect(fn () => app(ClearOwnerMediaSlotAction::class)->execute(
            $actorData,
            $owner,
            'document',
            $key,
        ))->toThrow(RuntimeException::class, 'Injected clear completion failure.');
    } finally {
        MediaOwnerSlotOperation::flushEventListeners();
    }

    expect($owner->fresh()->getFirstMedia('document')?->id)->toBe($media->id)
        ->and(Media::query()->find($media->id))->not->toBeNull()
        ->and(Storage::disk('public')->exists($media->buildPath()))->toBeTrue();

    $operation = MediaOwnerSlotOperation::query()
        ->where('idempotency_key', $key)
        ->sole();

    expect($operation->status)->toBe(MediaOwnerSlotOperationStatus::Failed)
        ->and($operation->failure_code)->toBe('clear_failed');
});

it('copies through canonical ingestion with safe metadata and exact replay', function (): void {
    Storage::fake('public');
    config([
        'media.owner_slots.copy.metadata_keys' => [
            'approved',
            'credit',
            'license_year',
            'note',
        ],
    ]);

    $sourceOwner = ownerSlotWorkflowOwner('Source owner');
    $destination = ownerSlotWorkflowOwner('Destination');
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $source = ownerSlotStoredMedia('%PDF-1.4 canonical copy', [
        'filename' => 'source-document.pdf',
        'tags' => [' source ', 'proof', 'proof', ''],
        'metadata' => [
            'credit' => 'Studio',
            'license_year' => 2026,
            'approved' => true,
            'note' => null,
            'provider_payload' => 'secret',
            'redaction_reason' => 'private',
            'storage_path' => 'documents/forged.pdf',
            'hash' => 'forged',
            'access_token' => 'secret-token',
            'client_secret' => 'secret-client',
            'password' => 'secret-password',
            'credential' => 'secret-credential',
            'nested' => ['not' => 'copied'],
        ],
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($source, $sourceOwner, 'document');
    $previous = ownerSlotStoredMedia('%PDF-1.4 previous destination', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($previous, $destination, 'document');
    $key = Str::uuid()->toString();

    $copy = app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $destination,
        'document',
        $source->id,
        $key,
    );
    $replay = app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $destination,
        'document',
        $source->id,
        $key,
    );
    $copiedMedia = Media::query()->findOrFail($copy->id);

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->filename)->toBe('source-document.pdf')
        ->and($replay->toArray())->toBe($copy->toArray())
        ->and($destination->fresh()->getFirstMedia('document')?->id)->toBe($copy->id)
        ->and($copiedMedia->digest)->toBe($source->digest)
        ->and($copiedMedia->hash)->not->toBe($source->hash)
        ->and($copiedMedia->is_public)->toBeFalse()
        ->and($copiedMedia->uploaded_by)->toBe((string) $actor->getKey())
        ->and($copiedMedia->uploaded_by_type)->toBe($actor->getMorphClass())
        ->and($copiedMedia->tags)->toBe(['source', 'proof'])
        ->and($copiedMedia->metadata)->toEqual([
            'approved' => true,
            'credit' => 'Studio',
            'license_year' => 2026,
            'note' => null,
        ])
        ->and(Storage::disk('public')->get($copiedMedia->buildPath()))
        ->toBe('%PDF-1.4 canonical copy')
        ->and(Storage::disk('public')->exists($source->buildPath()))->toBeTrue()
        ->and(Storage::disk('public')->exists($previous->buildPath()))->toBeFalse()
        ->and(Media::withTrashed()->find($previous->id)?->trashed())->toBeTrue()
        ->and(Media::query()->count())->toBe(2);

    $customDestination = ownerSlotWorkflowOwner('Custom destination');
    $customCopy = app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $customDestination,
        'custom',
        $source->id,
    );

    expect($customDestination->fresh()->getFirstMedia('custom')?->id)
        ->toBe($customCopy->id);

    $publicDestination = ownerSlotWorkflowOwner('Public destination');
    $publicCopy = app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $publicDestination,
        'library',
        $source->id,
    );

    expect(Media::query()->findOrFail($publicCopy->id)->is_public)->toBeTrue();
});

it('recovers a committed copy from an expired processing checkpoint', function (): void {
    Storage::fake('public');
    config([
        'media.owner_slots.idempotency.processing_timeout_minutes' => 5,
    ]);

    $destination = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $source = ownerSlotStoredMedia('%PDF-1.4 checkpoint source', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $committedCopy = ownerSlotStoredMedia('%PDF-1.4 checkpoint copy', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($committedCopy, $destination, 'document');
    useOwnerSlotAuthorization(static fn (): bool => true);
    $key = Str::uuid()->toString();
    $idempotency = app(MediaOwnerSlotIdempotency::class);
    $claim = $idempotency->begin(
        key: $key,
        actor: $actorData,
        owner: $destination,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Copy,
        payload: ['source_media_id' => $source->id],
    );

    $idempotency->checkpoint($claim, $committedCopy->id);
    MediaOwnerSlotOperation::query()
        ->whereKey($claim->operationId)
        ->update(['updated_at' => now()->subMinutes(6)]);

    $recovered = app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $destination,
        'document',
        $source->id,
        $key,
    );

    expect($recovered->id)->toBe($committedCopy->id)
        ->and($destination->fresh()->getFirstMedia('document')?->id)
        ->toBe($committedCopy->id)
        ->and(Media::query()->count())->toBe(2);

    $operation = MediaOwnerSlotOperation::query()
        ->where('idempotency_key', $key)
        ->sole();

    expect($operation->status)->toBe(MediaOwnerSlotOperationStatus::Completed)
        ->and($operation->result_media_id)->toBe($committedCopy->id)
        ->and(MediaLibraryItem::from($operation->result_payload)->toArray())
        ->toBe($recovered->toArray());
});

it('exactly replays a copy after its exclusive source is deleted', function (): void {
    Storage::fake('public');

    $owner = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $source = ownerSlotStoredMedia('%PDF-1.4 self replacement source', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($source, $owner, 'document');
    $key = Str::uuid()->toString();

    $copy = app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $source->id,
        $key,
    );
    $replay = app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $owner,
        'document',
        $source->id,
        $key,
    );

    expect(Media::withTrashed()->findOrFail($source->id)->trashed())->toBeTrue()
        ->and($replay->toArray())->toBe($copy->toArray())
        ->and($owner->fresh()->getFirstMedia('document')?->id)->toBe($copy->id)
        ->and(Media::query()->count())->toBe(1);
});

it('rejects unauthorized, missing, and corrupt copy sources before mutation', function (): void {
    Storage::fake('public');

    $destination = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $source = ownerSlotStoredMedia('%PDF-1.4 authorized', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $startingCount = Media::query()->count();

    useOwnerSlotAuthorization(
        static fn (
            MediaActorData $candidateActor,
            MediaAbility $ability,
        ): bool => $ability === MediaAbility::Associate,
    );

    expect(fn () => app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $destination,
        'document',
        $source->id,
    ))->toThrow(AuthorizationException::class, 'view');

    useOwnerSlotAuthorization(
        static fn (
            MediaActorData $candidateActor,
            MediaAbility $ability,
        ): bool => $ability === MediaAbility::View,
    );

    expect(fn () => app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $destination,
        'document',
        $source->id,
    ))->toThrow(AuthorizationException::class, 'associate');

    useOwnerSlotAuthorization(static fn (): bool => true);
    Storage::disk('public')->delete($source->buildPath());

    expect(fn () => app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $destination,
        'document',
        $source->id,
    ))->toThrow(MediaUploadException::class, 'source object');

    Storage::disk('public')->put($source->buildPath(), '%PDF-1.4 tampered');

    expect(fn () => app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $destination,
        'document',
        $source->id,
    ))->toThrow(MediaUploadException::class, 'checksum');

    expect(Media::query()->count())->toBe($startingCount)
        ->and($destination->fresh()->getFirstMedia('document'))->toBeNull();
});

it('rolls back copied rows and objects when destination replacement fails', function (): void {
    Storage::fake('public');

    $destination = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $source = ownerSlotStoredMedia('%PDF-1.4 copy rollback', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $current = ownerSlotStoredMedia('%PDF-1.4 current destination', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($current, $destination, 'document');
    $key = Str::uuid()->toString();
    $startingFiles = Storage::disk('public')->allFiles();
    sort($startingFiles);
    useOwnerSlotAuthorization(static fn (): bool => true);

    app()->instance(DetachMediaContract::class, new class implements DetachMediaContract
    {
        public function execute(
            Media|string $media,
            Model $model,
            ?string $collection = null,
        ): int {
            throw new RuntimeException('Injected copy detach failure.');
        }
    });

    expect(fn () => app(CopyOwnerMediaSlotAction::class)->execute(
        $actorData,
        $destination,
        'document',
        $source->id,
        $key,
    ))->toThrow(RuntimeException::class, 'Injected copy detach failure');

    $remainingFiles = Storage::disk('public')->allFiles();
    sort($remainingFiles);

    expect($destination->fresh()->getFirstMedia('document')?->id)->toBe($current->id)
        ->and(Media::query()->count())->toBe(2)
        ->and($remainingFiles)->toBe($startingFiles)
        ->and(app(MediaTemporaryFileRegistry::class)->count())->toBe(0);

    $operation = MediaOwnerSlotOperation::query()
        ->where('idempotency_key', $key)
        ->sole();

    expect($operation->status)->toBe(MediaOwnerSlotOperationStatus::Failed)
        ->and($operation->failure_code)->toBe('copy_failed');
});

it('rolls back a copied slot when same-connection idempotency completion fails', function (): void {
    Storage::fake('public');

    $destination = ownerSlotWorkflowOwner();
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $source = ownerSlotStoredMedia('%PDF-1.4 completion source', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    $current = ownerSlotStoredMedia('%PDF-1.4 completion current', [
        'uploaded_by' => (string) $actor->getKey(),
        'uploaded_by_type' => $actor->getMorphClass(),
    ]);
    attachOwnerSlotMedia($current, $destination, 'document');
    $key = Str::uuid()->toString();
    $startingFiles = Storage::disk('public')->allFiles();
    sort($startingFiles);
    useOwnerSlotAuthorization(static fn (): bool => true);

    MediaOwnerSlotOperation::updating(
        static function (MediaOwnerSlotOperation $operation): void {
            if ($operation->status === MediaOwnerSlotOperationStatus::Completed) {
                throw new RuntimeException('Injected owner-slot completion failure.');
            }
        },
    );

    try {
        expect(fn () => app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $destination,
            'document',
            $source->id,
            $key,
        ))->toThrow(RuntimeException::class, 'Injected owner-slot completion failure.');
    } finally {
        MediaOwnerSlotOperation::flushEventListeners();
    }

    $remainingFiles = Storage::disk('public')->allFiles();
    sort($remainingFiles);

    expect($destination->fresh()->getFirstMedia('document')?->id)->toBe($current->id)
        ->and(Media::query()->count())->toBe(2)
        ->and($remainingFiles)->toBe($startingFiles);

    $operation = MediaOwnerSlotOperation::query()
        ->where('idempotency_key', $key)
        ->sole();

    expect($operation->status)->toBe(MediaOwnerSlotOperationStatus::Failed)
        ->and($operation->failure_code)->toBe('copy_failed');
});

it('preserves and recovers a split-connection checkpoint when completion fails', function (): void {
    Storage::fake('public');
    $ledgerPath = tempnam(sys_get_temp_dir(), 'media_owner_slot_ledger_');

    if (! is_string($ledgerPath)) {
        throw new RuntimeException('Unable to create the owner-slot ledger database.');
    }

    config([
        'database.connections.owner_slot_ledger' => [
            'driver' => 'sqlite',
            'database' => $ledgerPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'media.owner_slots.idempotency.connection' => 'owner_slot_ledger',
        'media.owner_slots.idempotency.processing_timeout_minutes' => 5,
    ]);
    $migration = require __DIR__.'/../../database/migrations/2026_08_28_000000_create_media_owner_slot_operations_table.php';
    $migration->up();

    try {
        $sourceOwner = ownerSlotWorkflowOwner('Split source owner');
        $destination = ownerSlotWorkflowOwner('Split destination');
        $actor = ownerSlotWorkflowActor();
        $actorData = ownerSlotWorkflowActorData($actor);
        $source = ownerSlotStoredMedia('%PDF-1.4 split checkpoint', [
            'uploaded_by' => (string) $actor->getKey(),
            'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        attachOwnerSlotMedia($source, $sourceOwner, 'document');
        $key = Str::uuid()->toString();
        useOwnerSlotAuthorization(static fn (): bool => true);

        DB::connection('owner_slot_ledger')->unprepared(sprintf(
            'CREATE TRIGGER media_owner_slot_split_completion_failure
            BEFORE UPDATE OF status ON %s
            WHEN NEW.status = "completed"
            BEGIN
                SELECT RAISE(ABORT, "injected split completion failure");
            END',
            MediaTables::OwnerSlotOperations,
        ));

        expect(fn () => app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $destination,
            'document',
            $source->id,
            $key,
        ))->toThrow(QueryException::class, 'injected split completion failure');

        $committedCopyId = $destination->fresh()->getFirstMedia('document')?->id;
        $operation = MediaOwnerSlotOperation::query()
            ->where('idempotency_key', $key)
            ->sole();

        expect($committedCopyId)->not->toBeNull()
            ->and($operation->status)->toBe(MediaOwnerSlotOperationStatus::Processing)
            ->and($operation->result_media_id)->toBe($committedCopyId)
            ->and($operation->result_payload)->toBeArray()
            ->and(Media::query()->count())->toBe(2);

        DB::connection('owner_slot_ledger')->unprepared(
            'DROP TRIGGER IF EXISTS media_owner_slot_split_completion_failure',
        );
        $newer = ownerSlotStoredMedia('%PDF-1.4 newer destination', [
            'uploaded_by' => (string) $actor->getKey(),
            'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        app(ReplaceOwnerMediaSlotAction::class)->execute(
            $actorData,
            $destination,
            'document',
            $newer->id,
        );
        $operation->forceFill(['updated_at' => now()->subMinutes(6)])->save();

        $recovered = app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $destination,
            'document',
            $source->id,
            $key,
        );

        expect($recovered->id)->toBe($committedCopyId)
            ->and($destination->fresh()->getFirstMedia('document')?->id)
            ->toBe($newer->id)
            ->and(Media::withTrashed()->find($committedCopyId)?->trashed())->toBeTrue()
            ->and(Media::query()->count())->toBe(2)
            ->and(MediaOwnerSlotOperation::query()
                ->where('idempotency_key', $key)
                ->sole()
                ->status)
            ->toBe(MediaOwnerSlotOperationStatus::Completed);
    } finally {
        DB::connection('owner_slot_ledger')->unprepared(
            'DROP TRIGGER IF EXISTS media_owner_slot_split_completion_failure',
        );
        DB::purge('owner_slot_ledger');
        unlink($ledgerPath);
    }
});

it('preserves a split checkpoint when an after-commit listener throws', function (): void {
    Storage::fake('public');
    $ledgerPath = tempnam(sys_get_temp_dir(), 'media_owner_slot_listener_');

    if (! is_string($ledgerPath)) {
        throw new RuntimeException('Unable to create the owner-slot ledger database.');
    }

    config([
        'database.connections.owner_slot_listener' => [
            'driver' => 'sqlite',
            'database' => $ledgerPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'media.owner_slots.idempotency.connection' => 'owner_slot_listener',
        'media.owner_slots.idempotency.processing_timeout_minutes' => 5,
    ]);
    $migration = require __DIR__.'/../../database/migrations/2026_08_28_000000_create_media_owner_slot_operations_table.php';
    $migration->up();

    try {
        $sourceOwner = ownerSlotWorkflowOwner('Listener source owner');
        $destination = ownerSlotWorkflowOwner('Listener destination');
        $actor = ownerSlotWorkflowActor();
        $actorData = ownerSlotWorkflowActorData($actor);
        $source = ownerSlotStoredMedia('%PDF-1.4 listener checkpoint', [
            'uploaded_by' => (string) $actor->getKey(),
            'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        attachOwnerSlotMedia($source, $sourceOwner, 'document');
        $key = Str::uuid()->toString();
        useOwnerSlotAuthorization(static fn (): bool => true);
        Event::listen(
            MediaAttached::class,
            static function (): never {
                throw new RuntimeException('Injected after-commit listener failure.');
            },
        );

        expect(fn () => app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $destination,
            'document',
            $source->id,
            $key,
        ))->toThrow(RuntimeException::class, 'Injected after-commit listener failure');

        $committedCopyId = $destination->fresh()->getFirstMedia('document')?->id;
        $operation = MediaOwnerSlotOperation::query()
            ->where('idempotency_key', $key)
            ->sole();

        expect($committedCopyId)->not->toBeNull()
            ->and($operation->status)->toBe(MediaOwnerSlotOperationStatus::Processing)
            ->and($operation->result_media_id)->toBe($committedCopyId)
            ->and($operation->result_payload)->toBeArray();

        Event::forget(MediaAttached::class);
        $operation->forceFill(['updated_at' => now()->subMinutes(6)])->save();

        $recovered = app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $destination,
            'document',
            $source->id,
            $key,
        );

        expect($recovered->id)->toBe($committedCopyId)
            ->and(Media::query()->count())->toBe(2);
    } finally {
        Event::forget(MediaAttached::class);
        DB::purge('owner_slot_listener');
        unlink($ledgerPath);
    }
});

it('recovers a split clear checkpoint without clearing a newer attachment', function (): void {
    Storage::fake('public');
    $ledgerPath = tempnam(sys_get_temp_dir(), 'media_owner_slot_clear_');

    if (! is_string($ledgerPath)) {
        throw new RuntimeException('Unable to create the owner-slot ledger database.');
    }

    config([
        'database.connections.owner_slot_clear' => [
            'driver' => 'sqlite',
            'database' => $ledgerPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'media.owner_slots.idempotency.connection' => 'owner_slot_clear',
        'media.owner_slots.idempotency.processing_timeout_minutes' => 5,
    ]);
    $migration = require __DIR__.'/../../database/migrations/2026_08_28_000000_create_media_owner_slot_operations_table.php';
    $migration->up();

    try {
        $owner = ownerSlotWorkflowOwner('Split clear owner');
        $actor = ownerSlotWorkflowActor();
        $actorData = ownerSlotWorkflowActorData($actor);
        $cleared = ownerSlotStoredMedia('%PDF-1.4 split clear', [
            'uploaded_by' => (string) $actor->getKey(),
            'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        attachOwnerSlotMedia($cleared, $owner, 'document');
        $key = Str::uuid()->toString();
        useOwnerSlotAuthorization(static fn (): bool => true);

        DB::connection('owner_slot_clear')->unprepared(sprintf(
            'CREATE TRIGGER media_owner_slot_split_clear_completion_failure
            BEFORE UPDATE OF status ON %s
            WHEN NEW.status = "completed"
            BEGIN
                SELECT RAISE(ABORT, "injected split clear completion failure");
            END',
            MediaTables::OwnerSlotOperations,
        ));

        expect(fn () => app(ClearOwnerMediaSlotAction::class)->execute(
            $actorData,
            $owner,
            'document',
            $key,
        ))->toThrow(QueryException::class, 'injected split clear completion failure');

        $operation = MediaOwnerSlotOperation::query()
            ->where('idempotency_key', $key)
            ->sole();

        expect($owner->fresh()->getFirstMedia('document'))->toBeNull()
            ->and($operation->status)->toBe(MediaOwnerSlotOperationStatus::Processing)
            ->and($operation->result_media_id)->toBeNull()
            ->and($operation->result_payload)->toBeArray();

        DB::connection('owner_slot_clear')->unprepared(
            'DROP TRIGGER IF EXISTS media_owner_slot_split_clear_completion_failure',
        );
        $newer = ownerSlotStoredMedia('%PDF-1.4 newer after clear', [
            'uploaded_by' => (string) $actor->getKey(),
            'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        app(ReplaceOwnerMediaSlotAction::class)->execute(
            $actorData,
            $owner,
            'document',
            $newer->id,
        );
        $operation->forceFill(['updated_at' => now()->subMinutes(6)])->save();

        app(ClearOwnerMediaSlotAction::class)->execute(
            $actorData,
            $owner,
            'document',
            $key,
        );

        expect($owner->fresh()->getFirstMedia('document')?->id)->toBe($newer->id)
            ->and(Media::query()->count())->toBe(1)
            ->and(MediaOwnerSlotOperation::query()
                ->where('idempotency_key', $key)
                ->sole()
                ->status)
            ->toBe(MediaOwnerSlotOperationStatus::Completed);
    } finally {
        DB::connection('owner_slot_clear')->unprepared(
            'DROP TRIGGER IF EXISTS media_owner_slot_split_clear_completion_failure',
        );
        DB::purge('owner_slot_clear');
        unlink($ledgerPath);
    }
});

it('completes split clear claims only at an outer transaction root', function (): void {
    Storage::fake('public');
    $connection = 'owner_slot_clear_outer';
    $ledgerPath = useOwnerSlotLedger($connection, 'media_owner_slot_clear_outer_');

    try {
        $actor = ownerSlotWorkflowActor();
        $actorData = ownerSlotWorkflowActorData($actor);
        useOwnerSlotAuthorization(static fn (): bool => true);

        $committedOwner = ownerSlotWorkflowOwner('Outer committed clear');
        $committedMedia = ownerSlotStoredMedia('%PDF-1.4 outer committed clear');
        attachOwnerSlotMedia($committedMedia, $committedOwner, 'document');
        $committedKey = Str::uuid()->toString();

        DB::beginTransaction();
        app(ClearOwnerMediaSlotAction::class)->execute(
            $actorData,
            $committedOwner,
            'document',
            $committedKey,
        );

        expect(MediaOwnerSlotOperation::query()
            ->where('idempotency_key', $committedKey)
            ->sole()
            ->status)->toBe(MediaOwnerSlotOperationStatus::Processing);

        DB::commit();

        expect($committedOwner->fresh()->getFirstMedia('document'))->toBeNull()
            ->and(MediaOwnerSlotOperation::query()
                ->where('idempotency_key', $committedKey)
                ->sole()
                ->status)
            ->toBe(MediaOwnerSlotOperationStatus::Completed);

        $rolledBackOwner = ownerSlotWorkflowOwner('Outer rolled-back clear');
        $rolledBackMedia = ownerSlotStoredMedia('%PDF-1.4 outer rolled-back clear');
        attachOwnerSlotMedia($rolledBackMedia, $rolledBackOwner, 'document');
        $rolledBackKey = Str::uuid()->toString();

        DB::beginTransaction();
        app(ClearOwnerMediaSlotAction::class)->execute(
            $actorData,
            $rolledBackOwner,
            'document',
            $rolledBackKey,
        );

        expect(MediaOwnerSlotOperation::query()
            ->where('idempotency_key', $rolledBackKey)
            ->sole()
            ->status)->toBe(MediaOwnerSlotOperationStatus::Processing);

        DB::rollBack();

        expect($rolledBackOwner->fresh()->getFirstMedia('document')?->id)
            ->toBe($rolledBackMedia->id)
            ->and(MediaOwnerSlotOperation::query()
                ->where('idempotency_key', $rolledBackKey)
                ->sole()
                ->status)
            ->toBe(MediaOwnerSlotOperationStatus::Failed);

        app(ClearOwnerMediaSlotAction::class)->execute(
            $actorData,
            $rolledBackOwner,
            'document',
            $rolledBackKey,
        );

        expect($rolledBackOwner->fresh()->getFirstMedia('document'))->toBeNull();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        releaseOwnerSlotLedger($connection, $ledgerPath);
    }
});

it('completes split copy claims only at an outer transaction root', function (): void {
    Storage::fake('public');
    $connection = 'owner_slot_copy_outer';
    $ledgerPath = useOwnerSlotLedger($connection, 'media_owner_slot_copy_outer_');

    try {
        $sourceOwner = ownerSlotWorkflowOwner('Outer copy source');
        $actor = ownerSlotWorkflowActor();
        $actorData = ownerSlotWorkflowActorData($actor);
        $source = ownerSlotStoredMedia('%PDF-1.4 outer copy source', [
            'uploaded_by' => (string) $actor->getKey(),
            'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        attachOwnerSlotMedia($source, $sourceOwner, 'document');
        useOwnerSlotAuthorization(static fn (): bool => true);

        $committedOwner = ownerSlotWorkflowOwner('Outer committed copy');
        $committedKey = Str::uuid()->toString();

        DB::beginTransaction();
        $committedCopy = app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $committedOwner,
            'document',
            $source->id,
            $committedKey,
        );

        expect(MediaOwnerSlotOperation::query()
            ->where('idempotency_key', $committedKey)
            ->sole()
            ->status)->toBe(MediaOwnerSlotOperationStatus::Processing);

        DB::commit();

        expect($committedOwner->fresh()->getFirstMedia('document')?->id)
            ->toBe($committedCopy->id)
            ->and(MediaOwnerSlotOperation::query()
                ->where('idempotency_key', $committedKey)
                ->sole()
                ->status)
            ->toBe(MediaOwnerSlotOperationStatus::Completed);

        $rolledBackOwner = ownerSlotWorkflowOwner('Outer rolled-back copy');
        $rolledBackKey = Str::uuid()->toString();

        DB::beginTransaction();
        $rolledBackCopy = app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $rolledBackOwner,
            'document',
            $source->id,
            $rolledBackKey,
        );

        expect(MediaOwnerSlotOperation::query()
            ->where('idempotency_key', $rolledBackKey)
            ->sole()
            ->status)->toBe(MediaOwnerSlotOperationStatus::Processing);

        DB::rollBack();

        expect($rolledBackOwner->fresh()->getFirstMedia('document'))->toBeNull()
            ->and(Media::withTrashed()->find($rolledBackCopy->id))->toBeNull()
            ->and(MediaOwnerSlotOperation::query()
                ->where('idempotency_key', $rolledBackKey)
                ->sole()
                ->status)
            ->toBe(MediaOwnerSlotOperationStatus::Failed);

        $retriedCopy = app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $rolledBackOwner,
            'document',
            $source->id,
            $rolledBackKey,
        );

        expect($retriedCopy->id)->not->toBe($rolledBackCopy->id)
            ->and($rolledBackOwner->fresh()->getFirstMedia('document')?->id)
            ->toBe($retriedCopy->id);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        releaseOwnerSlotLedger($connection, $ledgerPath);
    }
});

it('rolls back copy before Media callbacks when split checkpoint persistence fails', function (): void {
    Storage::fake('public');
    $connection = 'owner_slot_checkpoint_failure';
    $ledgerPath = useOwnerSlotLedger($connection, 'media_owner_slot_checkpoint_failure_');
    $attachedEvents = 0;

    try {
        $sourceOwner = ownerSlotWorkflowOwner('Checkpoint failure source');
        $destination = ownerSlotWorkflowOwner('Checkpoint failure destination');
        $actor = ownerSlotWorkflowActor();
        $actorData = ownerSlotWorkflowActorData($actor);
        $source = ownerSlotStoredMedia('%PDF-1.4 checkpoint failure source', [
            'uploaded_by' => (string) $actor->getKey(),
            'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        $current = ownerSlotStoredMedia('%PDF-1.4 checkpoint failure current');
        attachOwnerSlotMedia($source, $sourceOwner, 'document');
        attachOwnerSlotMedia($current, $destination, 'document');
        $key = Str::uuid()->toString();
        useOwnerSlotAuthorization(static fn (): bool => true);
        Event::listen(MediaAttached::class, static function () use (&$attachedEvents): void {
            $attachedEvents++;
        });

        DB::connection($connection)->unprepared(sprintf(
            'CREATE TRIGGER media_owner_slot_checkpoint_payload_failure
            BEFORE UPDATE OF result_payload ON %s
            WHEN NEW.result_payload IS NOT NULL AND OLD.result_payload IS NULL
            BEGIN
                SELECT RAISE(ABORT, "injected split checkpoint payload failure");
            END',
            MediaTables::OwnerSlotOperations,
        ));

        expect(fn () => app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $destination,
            'document',
            $source->id,
            $key,
        ))->toThrow(QueryException::class, 'injected split checkpoint payload failure');

        expect($destination->fresh()->getFirstMedia('document')?->id)->toBe($current->id)
            ->and(Media::query()->count())->toBe(2)
            ->and($attachedEvents)->toBe(0)
            ->and(MediaOwnerSlotOperation::query()
                ->where('idempotency_key', $key)
                ->sole()
                ->status)
            ->toBe(MediaOwnerSlotOperationStatus::Failed);
    } finally {
        Event::forget(MediaAttached::class);
        DB::connection($connection)->unprepared(
            'DROP TRIGGER IF EXISTS media_owner_slot_checkpoint_payload_failure',
        );
        releaseOwnerSlotLedger($connection, $ledgerPath);
    }
});

it('reconciles a clear checkpoint again under the owner mutation lock', function (): void {
    Storage::fake('public');
    config(['media.owner_slots.idempotency.processing_timeout_minutes' => 1]);
    $owner = ownerSlotWorkflowOwner('Clear checkpoint race owner');
    $actor = ownerSlotWorkflowActor();
    $actorData = ownerSlotWorkflowActorData($actor);
    $original = ownerSlotStoredMedia('%PDF-1.4 clear race original');
    $newer = ownerSlotStoredMedia('%PDF-1.4 clear race newer');
    $originalAssociation = attachOwnerSlotMedia($original, $owner, 'document');
    $newerAssociationId = Str::uuid()->toString();
    $key = Str::uuid()->toString();
    $idempotency = app(MediaOwnerSlotIdempotency::class);
    $claim = $idempotency->begin(
        key: $key,
        actor: $actorData,
        owner: $owner,
        slot: 'document',
        operation: MediaOwnerSlotOperationType::Clear,
        payload: [],
    );
    $idempotency->checkpoint($claim, null, [
        'authorization_association_id' => $originalAssociation->id,
        'authorization_media_id' => $original->id,
    ]);
    MediaOwnerSlotOperation::query()
        ->whereKey($claim->operationId)
        ->update(['updated_at' => now()->subMinutes(6)]);
    useOwnerSlotAuthorization(static fn (): bool => true);
    $retrieved = 0;
    $event = 'eloquent.retrieved: '.MediaAssociation::class;

    Event::listen($event, static function (MediaAssociation $association) use (
        &$retrieved,
        $newer,
        $newerAssociationId,
    ): void {
        $retrieved++;

        if ($retrieved !== 2) {
            return;
        }

        DB::table(MediaTables::Associations)
            ->where('id', $association->id)
            ->delete();
        DB::table(MediaTables::Associations)->insert([
            'id' => $newerAssociationId,
            'media_id' => $newer->id,
            'associable_type' => $association->associable_type,
            'associable_id' => $association->associable_id,
            'collection' => $association->collection,
            'locale' => null,
            'order' => 0,
            'is_active' => true,
            'replaced_at' => null,
            'metadata' => json_encode(['slot' => $association->collection], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        app(ClearOwnerMediaSlotAction::class)->execute(
            $actorData,
            $owner,
            'document',
            $key,
        );

        expect($owner->fresh()->getFirstMedia('document')?->id)->toBe($newer->id)
            ->and(MediaAssociation::query()->whereKey($newerAssociationId)->exists())->toBeTrue()
            ->and(MediaOwnerSlotOperation::query()
                ->where('idempotency_key', $key)
                ->sole()
                ->status)
            ->toBe(MediaOwnerSlotOperationStatus::Completed);
    } finally {
        Event::forget($event);
    }
});

it('fences and reconciles a copy checkpoint after acquiring the owner lock', function (): void {
    Storage::fake('public');
    $connection = 'owner_slot_copy_fence';
    $ledgerPath = useOwnerSlotLedger($connection, 'media_owner_slot_copy_fence_');
    $event = 'eloquent.saving: '.MediaOwnerSlotOperation::class;

    try {
        $sourceOwner = ownerSlotWorkflowOwner('Copy fence source');
        $destination = ownerSlotWorkflowOwner('Copy fence destination');
        $actor = ownerSlotWorkflowActor();
        $actorData = ownerSlotWorkflowActorData($actor);
        $source = ownerSlotStoredMedia('%PDF-1.4 copy fence source', [
            'uploaded_by' => (string) $actor->getKey(),
            'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        attachOwnerSlotMedia($source, $sourceOwner, 'document');
        $checkpointMedia = ownerSlotStoredMedia('%PDF-1.4 copy fence result', [
            'uploaded_by' => (string) $actor->getKey(),
            'uploaded_by_type' => $actor->getMorphClass(),
        ]);
        $checkpointAssociation = attachOwnerSlotMedia(
            $checkpointMedia,
            $destination,
            'document',
        );
        $mediaAttributes = $checkpointMedia->getAttributes();
        $associationAttributes = $checkpointAssociation->getAttributes();
        $checkpointMedia->loadMissing(['translations', 'imageVariations']);
        $checkpointMedia->loadCount('associations');
        $checkpointResult = app(MediaLibraryItemDataFactory::class)->fromAssociation(
            $checkpointMedia,
            $checkpointAssociation,
        );
        DB::table(MediaTables::Associations)
            ->where('id', $checkpointAssociation->id)
            ->delete();
        DB::table(MediaTables::Media)
            ->where('id', $checkpointMedia->id)
            ->delete();

        $key = Str::uuid()->toString();
        $idempotency = app(MediaOwnerSlotIdempotency::class);
        $claim = $idempotency->begin(
            key: $key,
            actor: $actorData,
            owner: $destination,
            slot: 'document',
            operation: MediaOwnerSlotOperationType::Copy,
            payload: ['source_media_id' => $source->id],
        );
        $idempotency->checkpoint(
            $claim,
            $checkpointMedia->id,
            $checkpointResult->toArray(),
        );
        MediaOwnerSlotOperation::query()
            ->whereKey($claim->operationId)
            ->update(['updated_at' => now()->subHour()]);
        useOwnerSlotAuthorization(static fn (): bool => true);
        $restored = false;
        $operationSaves = 0;

        Event::listen(
            $event,
            static function (MediaOwnerSlotOperation $operation) use (
                &$operationSaves,
                &$restored,
                $associationAttributes,
                $mediaAttributes,
            ): void {
                $operationSaves++;

                if ($restored || $operationSaves !== 2) {
                    return;
                }

                $restored = true;
                DB::table(MediaTables::Media)->insert($mediaAttributes);
                DB::table(MediaTables::Associations)->insert($associationAttributes);
            },
        );

        $result = app(CopyOwnerMediaSlotAction::class)->execute(
            $actorData,
            $destination,
            'document',
            $source->id,
            $key,
        );

        expect($restored)->toBeTrue()
            ->and($result->toArray())->toBe($checkpointResult->toArray())
            ->and($destination->fresh()->getFirstMedia('document')?->id)
            ->toBe($checkpointMedia->id)
            ->and(Media::query()->count())->toBe(2);
    } finally {
        Event::forget($event);
        releaseOwnerSlotLedger($connection, $ledgerPath);
    }
});
