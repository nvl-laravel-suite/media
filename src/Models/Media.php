<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Nvl\Media\Builders\MediaBuilder;
use Nvl\Media\Database\Factories\MediaFactory;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Support\MediaAssetUrl;
use Nvl\Media\Traits\MediaFilters;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Media: represents a stored file with variations, translations, and polymorphic associations.
 *
 * @property string $id
 * @property string $filename
 * @property string $hash
 * @property string $extension
 * @property string $mime_type
 * @property int $size
 * @property string $disk
 * @property string|null $folder
 * @property bool $is_public
 * @property MediaVisibility $visibility
 * @property MediaLifecycleStatus $status
 * @property int $revision
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_ms
 * @property MediaType $type
 * @property string $digest
 * @property array<int, string>|null $tags
 * @property array<string, mixed>|null $metadata
 * @property array<string, array<string, mixed>>|null $variation_definitions
 * @property string|null $uploaded_by
 * @property string|null $uploaded_by_type
 * @property string|null $upload_session_id
 * @property string|null $failure_code
 * @property array<string, mixed>|null $failure_context
 * @property Carbon|null $available_at
 * @property Carbon|null $quarantined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read EloquentCollection<int, MediaAssociation> $associations
 * @property-read EloquentCollection<int, MediaImageVariation> $imageVariations
 * @property-read EloquentCollection<int, MediaTranslation> $translations
 */
class Media extends Model implements TranslatableModel
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    use HasUuids;
    use MediaFilters;
    use SoftDeletes;
    use Translatable;

    public const string TABLE = MediaTables::MEDIA;

    protected $table = self::TABLE;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_public' => false,
        'visibility' => MediaVisibility::Private->value,
        'status' => MediaLifecycleStatus::Available->value,
        'revision' => 1,
    ];

    /** @var list<string> */
    protected $fillable = [
        'filename',
        'hash',
        'extension',
        'mime_type',
        'size',
        'disk',
        'folder',
        'is_public',
        'visibility',
        'status',
        'revision',
        'width',
        'height',
        'duration_ms',
        'available_at',
        'quarantined_at',
        'failure_code',
        'failure_context',
        'type',
        'digest',
        'tags',
        'metadata',
        'variation_definitions',
        'uploaded_by',
        'uploaded_by_type',
        'upload_session_id',
    ];

    /**
     * Configure localized media title, alternative text, caption, and description.
     */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: MediaTranslation::class,
            foreignKey: 'media_id',
            fields: ['title', 'alt', 'caption', 'description'],
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
        );
    }

    /**
     * Model attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'is_public' => 'boolean',
            'visibility' => MediaVisibility::class,
            'status' => MediaLifecycleStatus::class,
            'revision' => 'integer',
            'type' => MediaType::class,
            'tags' => 'array',
            'metadata' => 'array',
            'variation_definitions' => 'array',
            'failure_context' => 'array',
            'available_at' => 'datetime',
            'quarantined_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Create a package-namespaced media factory.
     */
    protected static function newFactory(): MediaFactory
    {
        return MediaFactory::new();
    }

    /**
     * Keep the indexed boolean projection synchronized with canonical visibility.
     */
    public function setIsPublicAttribute(bool $value): void
    {
        $this->attributes['is_public'] = $value;
        $this->attributes['visibility'] = $value
            ? MediaVisibility::Public->value
            : MediaVisibility::Private->value;
    }

    /**
     * Keep canonical visibility synchronized with its boolean query projection.
     */
    public function setVisibilityAttribute(MediaVisibility|string $visibility): void
    {
        $value = $visibility instanceof MediaVisibility ? $visibility->value : $visibility;
        $this->attributes['visibility'] = $value;
        $this->attributes['is_public'] = $value === MediaVisibility::Public->value;
    }

    /**
     * Register the custom Eloquent builder for media queries.
     *
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): MediaBuilder
    {
        return new MediaBuilder($query);
    }

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    /**
     * Polymorphic associations linking this media to models.
     *
     * @return HasMany<MediaAssociation, $this>
     */
    public function associations(): HasMany
    {
        return $this->hasMany(MediaAssociation::class, 'media_id');
    }

    /**
     * Generated image variations for this media.
     *
     * @return HasMany<MediaImageVariation, $this>
     */
    public function imageVariations(): HasMany
    {
        return $this->hasMany(MediaImageVariation::class, 'media_id');
    }

    /**
     * Resolve the polymorphic uploader stored with this media.
     *
     * @return MorphTo<Model, $this>
     */
    public function uploader(): MorphTo
    {
        return $this->morphTo(
            name: 'uploader',
            type: 'uploaded_by_type',
            id: 'uploaded_by',
        );
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    /**
     * Scope to publicly accessible media.
     *
     * @param  Builder<static>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('visibility', MediaVisibility::Public->value);
    }

    /**
     * Scope to private media.
     *
     * @param  Builder<static>  $query
     */
    public function scopePrivate(Builder $query): void
    {
        $query->where('visibility', MediaVisibility::Private->value);
    }

    /**
     * Scope to binaries that may be associated and delivered.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereIn('status', [
            MediaLifecycleStatus::Available->value,
            MediaLifecycleStatus::ProcessingVariations->value,
        ]);
    }

    /**
     * Determine whether this binary may be associated and delivered.
     */
    public function isAvailable(): bool
    {
        return ($this->status ?? MediaLifecycleStatus::Available)->isUsable();
    }

    /**
     * Scope to media of a given type.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOfType(Builder $query, MediaType $type): void
    {
        $query->where('type', $type->value);
    }

    /**
     * Scope to media stored on a specific disk.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOnDisk(Builder $query, string $disk): void
    {
        $query->where('disk', $disk);
    }

    /**
     * Scope to media containing a specific tag.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithTag(Builder $query, string $tag): void
    {
        $query->whereJsonContains('tags', $tag);
    }

    /* ---------------------------------------------------------------
     * URL / Path Helpers
     * ------------------------------------------------------------- */

    /**
     * Get the public or temporary URL for this media or a variation.
     */
    public function getUrl(string $variation = ''): string
    {
        return MediaAssetUrl::forMedia($this, $variation);
    }

    /**
     * Get the absolute filesystem path for this media or a variation.
     */
    public function getPath(string $variation = ''): string
    {
        return MediaAssetUrl::path($this, $variation);
    }

    /**
     * Generate a temporary signed URL.
     */
    public function getTemporaryUrl(DateTimeInterface $expiration, string $variation = ''): string
    {
        return MediaAssetUrl::temporaryUrl($this, $expiration, $variation);
    }

    /**
     * Build a centralized route URL for this media asset.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildUrl(
        array $parameters = [],
        ?DateTimeInterface $expiration = null,
        ?string $owner = null,
    ): string {
        return MediaAssetUrl::buildUrl($this, $parameters, $expiration, $owner);
    }

    /**
     * Build a centralized public asset URL.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildPublicUrl(array $parameters = []): string
    {
        return MediaAssetUrl::publicUrl($this, $parameters);
    }

    /**
     * Build a centralized signed private asset URL.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildPrivateUrl(
        array $parameters = [],
        ?DateTimeInterface $expiration = null,
        ?string $owner = null,
    ): string {
        return MediaAssetUrl::privateUrl($this, $parameters, $expiration, $owner);
    }

    /* ---------------------------------------------------------------
     * Variation Helpers
     * ------------------------------------------------------------- */

    /**
     * Get a specific image variation by label.
     *
     * Requires `imageVariations` to be loaded. Use `$media->loadMissing('imageVariations')` in loops.
     */
    public function getVariation(string $label): ?MediaImageVariation
    {
        /** @var MediaImageVariation|null */
        return $this->imageVariations->firstWhere('label', $label);
    }

    /**
     * Check if a variation with the given label exists.
     */
    public function hasVariation(string $label): bool
    {
        return $this->imageVariations->contains('label', $label);
    }

    /**
     * Get URL for a variation, falling back to original.
     */
    public function getVariationUrl(string $label): string
    {
        return $this->getUrl($label);
    }

    /**
     * Get absolute path for a variation, falling back to original.
     */
    public function getVariationPath(string $label): string
    {
        return $this->getPath($label);
    }

    /* ---------------------------------------------------------------
     * Usage Helpers
     * ------------------------------------------------------------- */

    /**
     * Check if this media is associated with any models.
     */
    public function isUsed(): bool
    {
        return $this->associations()->exists();
    }

    /**
     * Get a summary of all model associations.
     *
     * @return Collection<int, array{type: string, id: string, collection: string}>
     */
    public function getUsagesSummary(): Collection
    {
        return $this->associations->map(fn (MediaAssociation $a) => [
            'type' => $a->associable_type,
            'id' => $a->associable_id,
            'collection' => $a->collection,
        ]);
    }

    /* ---------------------------------------------------------------
     * Tag Helpers
     * ------------------------------------------------------------- */

    /**
     * Check if the media has a specific tag.
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags ?? [], true);
    }

    /* ---------------------------------------------------------------
     * Type Helpers
     * ------------------------------------------------------------- */

    /**
     * Check if this media is an image.
     */
    public function isImage(): bool
    {
        return $this->type === MediaType::IMAGE;
    }

    /**
     * Check if this media is a video.
     */
    public function isVideo(): bool
    {
        return $this->type === MediaType::VIDEO;
    }

    /**
     * Check if this media is a document.
     */
    public function isDocument(): bool
    {
        return $this->type === MediaType::DOCUMENT;
    }

    /**
     * Check if this media is audio.
     */
    public function isAudio(): bool
    {
        return $this->type === MediaType::AUDIO;
    }

    /**
     * Check if this media is an archive.
     */
    public function isArchive(): bool
    {
        return $this->type === MediaType::ARCHIVE;
    }

    /**
     * Get human-readable file size.
     */
    public function humanReadableSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->size;

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /* ---------------------------------------------------------------
     * Internal
     * ------------------------------------------------------------- */

    /**
     * Check if the file physically exists on disk.
     */
    public function fileExistsOnDisk(): bool
    {
        return MediaAssetUrl::fileExists($this);
    }

    /**
     * Build the relative storage path for the original media object.
     */
    public function buildPath(): string
    {
        return implode('/', array_filter([self::rootFolder(), $this->folder, $this->hash]));
    }

    /**
     * Get the configured root folder prefix for all media storage paths.
     */
    public static function rootFolder(): string
    {
        return MediaPathResolver::rootFolder();
    }

    /**
     * Prepend the root folder to a given folder path (for pre-persist storage operations).
     */
    public static function storagePath(string $folder): string
    {
        return MediaPathResolver::storagePath($folder);
    }
}
