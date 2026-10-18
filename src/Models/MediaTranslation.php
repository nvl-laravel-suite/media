<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Media\Database\Factories\MediaTranslationFactory;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Models\Concerns\GuardsTenantOwnership;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/**
 * @property-read string|null $owner_scope_key Technical immutable partition.
 * @property-read string|null $owner_reference Captured scalar reference on operational roots.
 *
 * MediaTranslation: locale-specific accessible metadata for a media record.
 * @property string $id
 * @property string|null $tenant_id Canonical tenant UUID inherited from media.
 * @property string|null $ownership_key Mixed catalog partition inherited from media.
 * @property string $media_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $alt
 * @property string|null $caption
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 */
class MediaTranslation extends Model implements Ownable
{
    use GuardsTenantOwnership;

    /** @use HasFactory<MediaTranslationFactory> */
    use HasFactory;

    use HasOwnership;
    use HasUuids;

    /** Inherit only from the canonical asset relation. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::inherited('media');
    }

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
        return $this->belongsTo(MediaModelRegistry::modelClass(), 'media_id');
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

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return MediaTables::get(MediaTables::I18n);
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
    protected static function newFactory(): MediaTranslationFactory
    {
        return MediaTranslationFactory::new();
    }
}
