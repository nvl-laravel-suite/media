<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Nvl\Media\Definitions\Tables\MediaTables;

/**
 * MediaAssociation: polymorphic pivot linking media to any associable model.
 *
 * @property string $id
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
 */
class MediaAssociation extends Model
{
    use HasUuids;

    public const string TABLE = MediaTables::MEDIA_ASSOCIATIONS;

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
}
