<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Media\Definitions\Tables\MediaTables;

/**
 * MediaTranslation: locale-specific accessible metadata for a media record.
 *
 * @property string $id
 * @property string $media_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $alt
 * @property string|null $caption
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaTranslation extends Model
{
    use HasUuids;

    public const string TABLE = MediaTables::I18n;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'media_id',
        'locale',
        'title',
        'alt',
        'caption',
        'description',
    ];

    /**
     * Model attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The media record this translation belongs to.
     *
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /**
     * Scope to a specific locale.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForLocale(Builder $query, string $locale): void
    {
        $query->where('locale', $locale);
    }
}
