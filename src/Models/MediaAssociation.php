<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Nvl\Media\Database\Factories\MediaAssociationFactory;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Models\Concerns\GuardsTenantOwnership;
use Nvl\Support\Config\PackageStorage;

/**
 * MediaAssociation: polymorphic pivot linking media to any associable model.
 *
 * @property string $id
 * @property string|null $tenant_id Canonical tenant UUID inherited from media.
 * @property string|null $ownership_key Mixed catalog partition inherited from media.
 * @property string $media_id
 * @property string $associable_type
 * @property string $associable_id
 * @property string $collection
 * @property string|null $locale
 * @property int $order
 * @property bool $is_active
 * @property Carbon|null $replaced_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 */
class MediaAssociation extends Model
{
    use GuardsTenantOwnership;

    /** @use HasFactory<MediaAssociationFactory> */
    use HasFactory;
    use HasUuids;

    public const string TABLE = MediaTables::Associations;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'media_id',
        'associable_type',
        'associable_id',
        'collection',
        'locale',
        'order',
        'is_active',
        'replaced_at',
        'metadata',
    ];

    /**
     * Model attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'is_active' => 'boolean',
            'replaced_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    /**
     * The media record this association points to.
     *
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /**
     * The polymorphic model this media is associated with.
     *
     * @return MorphTo<Model, $this>
     */
    public function associable(): MorphTo
    {
        return $this->morphTo();
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    /**
     * Scope to a specific collection name.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForCollection(Builder $query, string $collection): void
    {
        $query->where('collection', $collection);
    }

    /**
     * Scope to associations for a specific model instance.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForModel(Builder $query, Model $model): void
    {
        $query->where('associable_type', $model->getMorphClass())
            ->where('associable_id', $model->getKey());
    }

    /**
     * Scope to order by the order column.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('order');
    }

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return MediaTables::get(MediaTables::Associations);
    }

    /** Resolve the package connection through shared infrastructure defaults. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('media') ?? parent::getConnectionName());
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): MediaAssociationFactory
    {
        return MediaAssociationFactory::new();
    }
}
