<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Enums\MediaVisibility;

/**
 * Server-authoritative multipart upload state.
 *
 * @property string $id
 * @property array<string, mixed>|null $provider_state
 * @property string $disk
 * @property string $object_key
 * @property string $object_key_hash
 * @property string $display_filename
 * @property string $canonical_extension
 * @property string $declared_mime
 * @property int $expected_size
 * @property string $expected_checksum
 * @property MediaVisibility $visibility
 * @property string|null $uploader_id
 * @property string|null $uploader_type
 * @property Carbon $expires_at
 * @property int $part_size
 * @property int $expected_parts
 * @property int $minimum_part_size
 * @property int $maximum_part_size
 * @property int $maximum_parts
 * @property array<int, array{length: int, checksum: string}>|null $signed_parts
 * @property MediaMultipartStatus $status
 * @property string|null $completed_media_id
 * @property string|null $provider_object_identity
 * @property string|null $failure_code
 * @property array<string, mixed>|null $failure_context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MediaMultipartUpload extends Model
{
    use HasUuids;

    public const string TABLE = MediaTables::MultipartUploads;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'provider_state',
        'disk',
        'object_key',
        'object_key_hash',
        'display_filename',
        'canonical_extension',
        'declared_mime',
        'expected_size',
        'expected_checksum',
        'visibility',
        'uploader_id',
        'uploader_type',
        'expires_at',
        'part_size',
        'expected_parts',
        'minimum_part_size',
        'maximum_part_size',
        'maximum_parts',
        'signed_parts',
        'status',
        'completed_media_id',
        'provider_object_identity',
        'failure_code',
        'failure_context',
    ];

    /** @var list<string> */
    protected $hidden = [
        'provider_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_state' => 'encrypted:array',
            'expected_size' => 'integer',
            'visibility' => MediaVisibility::class,
            'expires_at' => 'immutable_datetime',
            'part_size' => 'integer',
            'expected_parts' => 'integer',
            'minimum_part_size' => 'integer',
            'maximum_part_size' => 'integer',
            'maximum_parts' => 'integer',
            'signed_parts' => 'array',
            'status' => MediaMultipartStatus::class,
            'failure_context' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Media created by this completed session.
     *
     * @return BelongsTo<Media, $this>
     */
    public function completedMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'completed_media_id');
    }
}
