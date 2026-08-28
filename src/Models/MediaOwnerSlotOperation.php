<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Media\Enums\MediaOwnerSlotOperationStatus;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Support\MediaConfiguration;

/**
 * Durable idempotency state for one Media owner-slot mutation.
 *
 * @property string $id
 * @property string $idempotency_key
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $slot
 * @property MediaOwnerSlotOperationType $operation
 * @property string $request_hash
 * @property MediaOwnerSlotOperationStatus $status
 * @property string|null $result_media_id
 * @property string|null $failure_code
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MediaOwnerSlotOperation extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
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
    ];

    public function getTable(): string
    {
        return MediaConfiguration::ownerSlotOperationTable();
    }

    public function getConnectionName(): ?string
    {
        return MediaConfiguration::ownerSlotOperationConnection()
            ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => MediaOwnerSlotOperationType::class,
            'status' => MediaOwnerSlotOperationStatus::class,
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
