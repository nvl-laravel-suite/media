<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Nvl\Media\Enums\MediaOwnerSlotOperationStatus;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Models\Concerns\GuardsTenantOwnership;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/**
 * @property-read string|null $owner_scope_key Technical immutable partition.
 * @property-read string|null $owner_reference Captured scalar reference on operational roots.
 *
 * Durable idempotency state for one Media owner-slot mutation.
 * @property string $id
 * @property string|null $tenant_id Canonical tenant UUID inherited from owner.
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
 * @property array<string, mixed>|null $result_payload
 * @property string|null $failure_code
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MediaOwnerSlotOperation extends Model implements Ownable
{
    use GuardsTenantOwnership;
    use HasOwnership;
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
        'result_payload',
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
        return PackageStorage::connectionName($this->connection ?? MediaConfiguration::ownerSlotOperationConnection() ?? parent::getConnectionName());
    }

    /**
     * Return the canonical polymorphic owner for this operation.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => MediaOwnerSlotOperationType::class,
            'status' => MediaOwnerSlotOperationStatus::class,
            'result_payload' => 'array',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
