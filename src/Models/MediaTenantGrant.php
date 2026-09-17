<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Models\Concerns\AppliesTenantBoundary;
use Nvl\Media\Models\Concerns\GuardsTenantOwnership;

/**
 * Records a recipient tenant's revocable permission to inspect and copy one platform asset.
 *
 * @property string $id Persisted UUID.
 * @property string $tenant_id Recipient tenant UUID.
 * @property string $media_id Platform source asset UUID.
 * @property int $source_revision Granted source revision.
 * @property int $revision Optimistic grant revision.
 * @property bool $enabled Whether new inspection and import is allowed.
 * @property Carbon|null $revoked_at Revocation time.
 * @property Carbon|null $created_at Creation time.
 * @property Carbon|null $updated_at Update time.
 * @property-read Media $media Platform source asset.
 */
final class MediaTenantGrant extends Model
{
    use AppliesTenantBoundary;
    use GuardsTenantOwnership;
    use HasUuids;

    public const string TABLE = MediaTables::TenantGrants;

    protected static function tenantResourceKey(): string
    {
        return 'media.catalog-grants';
    }

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id')->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_revision' => 'integer',
            'revision' => 'integer',
            'enabled' => 'boolean',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
