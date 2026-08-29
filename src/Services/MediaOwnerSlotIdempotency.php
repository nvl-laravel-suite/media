<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaOwnerSlotOperationStatus;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Support\MediaOwnerSlotOperationClaim;

/**
 * Owns portable idempotency claims for Media owner-slot mutations.
 */
final class MediaOwnerSlotIdempotency
{
    /**
     * Claim a request, return its completed result, or reject unsafe key reuse.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function begin(
        string $key,
        MediaActorData $actor,
        Model $owner,
        string $slot,
        MediaOwnerSlotOperationType $operation,
        array $payload,
    ): MediaOwnerSlotOperationClaim {
        $key = $this->idempotencyKey($key);
        $ownerIdentity = $this->ownerIdentity($owner);
        $actorIdentity = $this->actorIdentity($actor);
        $slot = $this->slot($slot);
        $requestHash = $this->requestHash(
            actor: $actorIdentity,
            owner: $ownerIdentity,
            slot: $slot,
            operation: $operation,
            payload: $payload,
        );
        $connection = (new MediaOwnerSlotOperation)->getConnectionName();

        try {
            return DB::connection($connection)->transaction(
                function () use (
                    $actorIdentity,
                    $key,
                    $operation,
                    $ownerIdentity,
                    $requestHash,
                    $slot,
                ): MediaOwnerSlotOperationClaim {
                    $existing = MediaOwnerSlotOperation::query()
                        ->where('idempotency_key', $key)
                        ->lockForUpdate()
                        ->first();

                    if ($existing instanceof MediaOwnerSlotOperation) {
                        return $this->resolveExisting($existing, $requestHash);
                    }

                    $created = MediaOwnerSlotOperation::query()->create([
                        'idempotency_key' => $key,
                        'actor_type' => $actorIdentity['type'],
                        'actor_id' => $actorIdentity['id'],
                        'owner_type' => $ownerIdentity['type'],
                        'owner_id' => $ownerIdentity['id'],
                        'slot' => $slot,
                        'operation' => $operation,
                        'request_hash' => $requestHash,
                        'status' => MediaOwnerSlotOperationStatus::Processing,
                    ]);

                    return $this->claim($created, replayed: false);
                },
                3,
            );
        } catch (UniqueConstraintViolationException) {
            return DB::connection($connection)->transaction(
                function () use ($key, $requestHash): MediaOwnerSlotOperationClaim {
                    $existing = MediaOwnerSlotOperation::query()
                        ->where('idempotency_key', $key)
                        ->lockForUpdate()
                        ->first();

                    if (! $existing instanceof MediaOwnerSlotOperation) {
                        throw new LogicException(
                            'The concurrent Media owner-slot claim could not be resolved.',
                        );
                    }

                    return $this->resolveExisting($existing, $requestHash);
                },
                3,
            );
        }
    }

    /**
     * Read an exact completed claim without creating or mutating ledger state.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function completed(
        string $key,
        MediaActorData $actor,
        Model $owner,
        string $slot,
        MediaOwnerSlotOperationType $operation,
        array $payload,
    ): ?MediaOwnerSlotOperationClaim {
        $key = $this->idempotencyKey($key);
        $requestHash = $this->requestHash(
            actor: $this->actorIdentity($actor),
            owner: $this->ownerIdentity($owner),
            slot: $this->slot($slot),
            operation: $operation,
            payload: $payload,
        );
        $existing = MediaOwnerSlotOperation::query()
            ->where('idempotency_key', $key)
            ->where('request_hash', $requestHash)
            ->where('status', MediaOwnerSlotOperationStatus::Completed->value)
            ->first();

        if (! $existing instanceof MediaOwnerSlotOperation) {
            return null;
        }

        return $this->claim($existing, replayed: true);
    }

    /**
     * Read an exact processing claim that contains durable recovery proof.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function checkpointed(
        string $key,
        MediaActorData $actor,
        Model $owner,
        string $slot,
        MediaOwnerSlotOperationType $operation,
        array $payload,
    ): ?MediaOwnerSlotOperationClaim {
        $key = $this->idempotencyKey($key);
        $requestHash = $this->requestHash(
            actor: $this->actorIdentity($actor),
            owner: $this->ownerIdentity($owner),
            slot: $this->slot($slot),
            operation: $operation,
            payload: $payload,
        );
        $existing = MediaOwnerSlotOperation::query()
            ->where('idempotency_key', $key)
            ->where('request_hash', $requestHash)
            ->where('status', MediaOwnerSlotOperationStatus::Processing->value)
            ->whereNotNull('result_payload')
            ->first();

        return $existing instanceof MediaOwnerSlotOperation
            ? $this->claim($existing, replayed: false)
            : null;
    }

    /**
     * Complete a claimed operation with its optional resulting Media UUID.
     *
     * @param  array<string, mixed>|null  $resultPayload
     */
    public function complete(
        MediaOwnerSlotOperationClaim $claim,
        ?string $resultMediaId,
        ?array $resultPayload = null,
    ): void {
        if ($resultMediaId !== null && ! Str::isUuid($resultMediaId)) {
            throw new InvalidArgumentException(
                'A Media owner-slot result must be a valid UUID or null.',
            );
        }

        $this->transition(
            claim: $claim,
            attributes: [
                'status' => MediaOwnerSlotOperationStatus::Completed,
                'result_media_id' => $resultMediaId,
                'result_payload' => $this->resultPayload($resultPayload),
                'failure_code' => null,
                'completed_at' => now(),
                'failed_at' => null,
            ],
        );
    }

    /**
     * Persist a recoverable result correlation while an operation is still processing.
     *
     * @param  array<string, mixed>|null  $resultPayload
     */
    public function checkpoint(
        MediaOwnerSlotOperationClaim $claim,
        ?string $resultMediaId,
        ?array $resultPayload = null,
    ): void {
        if ($resultMediaId !== null && ! Str::isUuid($resultMediaId)) {
            throw new InvalidArgumentException(
                'A Media owner-slot checkpoint requires a valid Media UUID or null.',
            );
        }

        $this->transition(
            claim: $claim,
            attributes: [
                'result_media_id' => $resultMediaId !== null
                    ? Str::lower($resultMediaId)
                    : null,
                'result_payload' => $this->resultPayload($resultPayload),
            ],
        );
    }

    /**
     * Record a retryable operation failure using a stable machine code.
     */
    public function fail(
        MediaOwnerSlotOperationClaim $claim,
        string $failureCode,
    ): void {
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $failureCode) !== 1) {
            throw new InvalidArgumentException(
                'Media owner-slot failures require a stable failure code.',
            );
        }

        $this->transition(
            claim: $claim,
            attributes: [
                'status' => MediaOwnerSlotOperationStatus::Failed,
                'result_media_id' => null,
                'result_payload' => null,
                'failure_code' => $failureCode,
                'completed_at' => null,
                'failed_at' => now(),
            ],
        );
    }

    /**
     * Renew the bounded processing lease for a still-active claim.
     */
    public function renew(MediaOwnerSlotOperationClaim $claim): void
    {
        $connection = (new MediaOwnerSlotOperation)->getConnectionName();

        DB::connection($connection)->transaction(
            function () use ($claim): void {
                $operation = $this->lockedProcessingClaim($claim);

                $operation->forceFill(['updated_at' => now()])->save();
            },
            3,
        );
    }

    /**
     * Delete expired terminal claims without loading an unbounded result set.
     */
    public function prune(
        ?int $retentionDays = null,
        ?int $chunkSize = null,
    ): int {
        $retentionDays ??= MediaConfiguration::integer(
            'media.owner_slots.idempotency.retention_days',
            7,
            1,
        );
        $chunkSize ??= MediaConfiguration::integer(
            'media.owner_slots.idempotency.prune_chunk',
            500,
            1,
        );

        if ($retentionDays < 1 || $chunkSize < 1 || $chunkSize > 1_000) {
            throw new InvalidArgumentException(
                'Media owner-slot pruning requires positive retention and a chunk size no greater than 1000.',
            );
        }

        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;

        do {
            $ids = MediaOwnerSlotOperation::query()
                ->where(function ($query) use ($cutoff): void {
                    $query
                        ->where(function ($completed) use ($cutoff): void {
                            $completed
                                ->where(
                                    'status',
                                    MediaOwnerSlotOperationStatus::Completed->value,
                                )
                                ->where('completed_at', '<', $cutoff);
                        })
                        ->orWhere(function ($failed) use ($cutoff): void {
                            $failed
                                ->where(
                                    'status',
                                    MediaOwnerSlotOperationStatus::Failed->value,
                                )
                                ->where('failed_at', '<', $cutoff);
                        });
                })
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deletedChunk = MediaOwnerSlotOperation::query()
                ->whereIn('id', $ids)
                ->where(function ($query) use ($cutoff): void {
                    $query
                        ->where(function ($completed) use ($cutoff): void {
                            $completed
                                ->where(
                                    'status',
                                    MediaOwnerSlotOperationStatus::Completed->value,
                                )
                                ->where('completed_at', '<', $cutoff);
                        })
                        ->orWhere(function ($failed) use ($cutoff): void {
                            $failed
                                ->where(
                                    'status',
                                    MediaOwnerSlotOperationStatus::Failed->value,
                                )
                                ->where('failed_at', '<', $cutoff);
                        });
                })
                ->delete();

            if (! is_int($deletedChunk)) {
                throw new LogicException(
                    'Media owner-slot pruning did not return a deleted row count.',
                );
            }

            $deleted += $deletedChunk;
        } while (count($ids) === $chunkSize);

        return $deleted;
    }

    /**
     * Resolve a locked existing claim.
     */
    private function resolveExisting(
        MediaOwnerSlotOperation $operation,
        string $requestHash,
    ): MediaOwnerSlotOperationClaim {
        if (! hash_equals($operation->request_hash, $requestHash)) {
            throw new LogicException(
                'The Media owner-slot idempotency key belongs to a different request.',
            );
        }

        return match ($operation->status) {
            MediaOwnerSlotOperationStatus::Completed => $this->claim(
                $operation,
                replayed: true,
            ),
            MediaOwnerSlotOperationStatus::Failed => $this->reclaim($operation),
            MediaOwnerSlotOperationStatus::Processing => $this->resolveProcessing(
                $operation,
            ),
        };
    }

    /**
     * Recover an expired processing lease or reject live contention.
     */
    private function resolveProcessing(
        MediaOwnerSlotOperation $operation,
    ): MediaOwnerSlotOperationClaim {
        $timeout = config(
            'media.owner_slots.idempotency.processing_timeout_minutes',
            30,
        );

        if (! is_int($timeout) || $timeout < 1 || $timeout > 1_440) {
            throw new InvalidArgumentException(
                'media.owner_slots.idempotency.processing_timeout_minutes must be between 1 and 1440.',
            );
        }

        $expiredBefore = now()->subMinutes($timeout);

        if ($operation->updated_at !== null
            && $operation->updated_at->isAfter($expiredBefore)) {
            throw new LogicException(
                'The Media owner-slot operation is already in progress.',
            );
        }

        return $this->reclaim($operation);
    }

    /**
     * Start a new attempt while invalidating the previous claim identifier.
     */
    private function reclaim(
        MediaOwnerSlotOperation $operation,
    ): MediaOwnerSlotOperationClaim {
        $checkpointMediaId = $operation->status === MediaOwnerSlotOperationStatus::Processing
            ? $operation->result_media_id
            : null;
        $checkpointPayload = $operation->status === MediaOwnerSlotOperationStatus::Processing
            && is_array($operation->result_payload)
                ? $operation->result_payload
                : null;

        $operation->forceFill([
            'id' => Str::uuid()->toString(),
            'status' => MediaOwnerSlotOperationStatus::Processing,
            'result_media_id' => $checkpointMediaId,
            'result_payload' => $checkpointPayload,
            'failure_code' => null,
            'completed_at' => null,
            'failed_at' => null,
            'created_at' => now(),
        ])->save();

        return $this->claim($operation, replayed: false);
    }

    /**
     * Apply one terminal transition under a row lock.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(
        MediaOwnerSlotOperationClaim $claim,
        array $attributes,
    ): void {
        $connection = (new MediaOwnerSlotOperation)->getConnectionName();

        DB::connection($connection)->transaction(
            function () use ($attributes, $claim): void {
                $operation = $this->lockedProcessingClaim($claim);

                $operation->forceFill($attributes)->save();
            },
            3,
        );
    }

    /**
     * Resolve and validate one claimed processing row under its caller's transaction.
     */
    private function lockedProcessingClaim(
        MediaOwnerSlotOperationClaim $claim,
    ): MediaOwnerSlotOperation {
        $operation = MediaOwnerSlotOperation::query()
            ->lockForUpdate()
            ->find($claim->operationId);

        if (! $operation instanceof MediaOwnerSlotOperation) {
            throw new LogicException(
                'The claimed Media owner-slot operation no longer exists.',
            );
        }

        if (! hash_equals($operation->request_hash, $claim->requestHash)) {
            throw new LogicException(
                'The Media owner-slot transition does not match the claimed request hash.',
            );
        }

        if ($operation->status !== MediaOwnerSlotOperationStatus::Processing) {
            throw new LogicException(
                'The claimed Media owner-slot operation is no longer in progress.',
            );
        }

        return $operation;
    }

    /**
     * Build the immutable public claim from persisted state.
     */
    private function claim(
        MediaOwnerSlotOperation $operation,
        bool $replayed,
    ): MediaOwnerSlotOperationClaim {
        return new MediaOwnerSlotOperationClaim(
            operationId: $operation->id,
            requestHash: $operation->request_hash,
            replayed: $replayed,
            resultMediaId: $operation->result_media_id,
            resultPayload: is_array($operation->result_payload)
                ? $operation->result_payload
                : null,
        );
    }

    /**
     * Validate and canonicalize one immutable completed-result snapshot.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function resultPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $canonical = $this->canonicalMap($payload);

        try {
            json_encode(
                $canonical,
                JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Media owner-slot result payloads must contain JSON-compatible scalar values.',
                previous: $exception,
            );
        }

        return $canonical;
    }

    /**
     * Canonicalize one result map while preserving its string-keyed contract.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function canonicalMap(array $payload): array
    {
        ksort($payload, SORT_STRING);

        return array_map(
            fn (mixed $item): mixed => $this->canonicalValue($item),
            $payload,
        );
    }

    /**
     * Validate and normalize one UUID idempotency key.
     */
    private function idempotencyKey(string $key): string
    {
        if (! Str::isUuid($key)) {
            throw new InvalidArgumentException(
                'Media owner-slot idempotency keys must be valid UUIDs.',
            );
        }

        return Str::lower($key);
    }

    /**
     * @return array{type: string|null, id: string|null, system: bool, signed: bool}
     */
    private function actorIdentity(MediaActorData $actor): array
    {
        $type = $this->nullableIdentityPart($actor->type, 191, 'actor type');
        $id = $this->nullableIdentityPart($actor->id, 255, 'actor identifier');

        return [
            'type' => $type,
            'id' => $id,
            'system' => $actor->system,
            'signed' => $actor->signed,
        ];
    }

    /**
     * @return array{type: string, id: string}
     */
    private function ownerIdentity(Model $owner): array
    {
        if (! $owner->exists) {
            throw new InvalidArgumentException(
                'Media owner-slot operations require a persisted owner.',
            );
        }

        $key = $owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException(
                'Media owner-slot operations require a scalar owner identifier.',
            );
        }

        $type = $owner->getMorphClass();
        $id = (string) $key;

        if ($type === '' || mb_strlen($type) > 191 || $id === '' || mb_strlen($id) > 255) {
            throw new InvalidArgumentException(
                'Media owner-slot operations require bounded owner identity values.',
            );
        }

        return ['type' => $type, 'id' => $id];
    }

    /**
     * Validate one nullable actor identity component.
     */
    private function nullableIdentityPart(
        int|string|null $value,
        int $maximumLength,
        string $label,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = (string) $value;

        if ($normalized === '' || mb_strlen($normalized) > $maximumLength) {
            throw new InvalidArgumentException(
                "Media owner-slot {$label} must be a bounded scalar value.",
            );
        }

        return $normalized;
    }

    /**
     * Validate one configured slot identifier.
     */
    private function slot(string $slot): string
    {
        if (trim($slot) === '' || mb_strlen($slot) > 100) {
            throw new InvalidArgumentException(
                'Media owner-slot names must be non-empty and at most 100 characters.',
            );
        }

        return $slot;
    }

    /**
     * Hash one canonical request without storing its mutation payload.
     *
     * @param  array{type: string|null, id: string|null, system: bool, signed: bool}  $actor
     * @param  array{type: string, id: string}  $owner
     * @param  array<array-key, mixed>  $payload
     */
    private function requestHash(
        array $actor,
        array $owner,
        string $slot,
        MediaOwnerSlotOperationType $operation,
        array $payload,
    ): string {
        try {
            $encoded = json_encode(
                $this->canonicalValue([
                    'version' => 1,
                    'actor' => $actor,
                    'owner' => $owner,
                    'slot' => $slot,
                    'operation' => $operation->value,
                    'payload' => $payload,
                ]),
                JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Media owner-slot payloads must contain JSON-compatible scalar values.',
                previous: $exception,
            );
        }

        return hash('sha256', $encoded);
    }

    /**
     * Canonicalize nested maps while retaining list order.
     */
    private function canonicalValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                'Media owner-slot payloads must contain only nested scalar values.',
            );
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalValue($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        return array_map(
            fn (mixed $item): mixed => $this->canonicalValue($item),
            $value,
        );
    }
}
