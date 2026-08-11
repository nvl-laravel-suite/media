<?php

declare(strict_types=1);

namespace Nvl\Media\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Support\MediaAssetUrl;
use Nvl\Media\Support\MediaMimeResolver;
use Nvl\Media\Support\MediaVariationFileNamer;

/**
 * MediaImageVariation: a generated image conversion (thumbnail, optimized, etc.) for a media record.
 *
 * @property string $id
 * @property string $media_id
 * @property string $label
 * @property string|null $storage_path
 * @property string $status
 * @property int $width
 * @property int $height
 * @property int $size
 * @property string $format
 * @property int $quality
 * @property int $source_revision
 * @property int $attempts
 * @property array<string, mixed>|null $failure_context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaImageVariation extends Model
{
    use HasUuids;

    public const string TABLE = MediaTables::ImageVariations;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'media_id',
        'label',
        'storage_path',
        'status',
        'width',
        'height',
        'size',
        'format',
        'quality',
        'source_revision',
        'attempts',
        'failure_context',
    ];

    /**
     * Model attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
            'quality' => 'integer',
            'source_revision' => 'integer',
            'attempts' => 'integer',
            'failure_context' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    /**
     * The parent media record this variation belongs to.
     *
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id')->withTrashed();
    }

    /* ---------------------------------------------------------------
     * Derived Path / URL Helpers
     * ------------------------------------------------------------- */

    /**
     * Build the filename for this variation.
     */
    public function getFilename(): string
    {
        if (is_string($this->storage_path) && $this->storage_path !== '') {
            return basename($this->storage_path);
        }

        $media = $this->resolveMedia();

        return MediaVariationFileNamer::make(
            $media->hash,
            $this->label,
            $this->width,
            $this->height,
            $this->format,
        );
    }

    /**
     * Build the relative storage path for this variation.
     */
    public function getPath(): string
    {
        if (is_string($this->storage_path) && $this->storage_path !== '') {
            return $this->storage_path;
        }

        $media = $this->resolveMedia();
        $baseFolder = Media::storagePath($media->folder ?? '');

        return implode('/', array_filter([$baseFolder, MediaPathResolver::conversionsFolder(), $this->getFilename()]));
    }

    /**
     * Resolve the parent media, loading the relation if not already eager-loaded.
     */
    private function resolveMedia(): Media
    {
        if (! $this->relationLoaded('media')) {
            $this->load('media');
        }

        /** @var Media */
        return $this->getRelation('media');
    }

    /**
     * Get URL, falling back to parent media if variation file is missing.
     */
    public function getUrl(): string
    {
        return MediaAssetUrl::forVariation($this);
    }

    /**
     * Get the MIME type for this variation's format.
     */
    public function getMimeType(): string
    {
        return MediaMimeResolver::extensionToMime($this->format);
    }
}
