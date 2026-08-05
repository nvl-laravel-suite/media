<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaConfiguration;
use Stringable;
use UnitEnum;

/** MediaPathResolver: single authority for all media storage path construction and validation. */
final class MediaPathResolver
{
    /* ---------------------------------------------------------------
     * Collection-Based Resolution (template interpolation)
     * ------------------------------------------------------------- */

    /**
     * Resolve the storage folder path for a model and collection.
     */
    public function resolve(Model $model, MediaSlot $slot): string
    {
        $template = $slot->pathTemplate;

        return $this->interpolate($template, $model, $slot->name);
    }

    /**
     * Normalize a caller-supplied folder while rejecting traversal and null bytes.
     */
    public function normalizeFolder(string $folder): string
    {
        return $this->sanitize($folder);
    }

    /**
     * Resolve the conversions subfolder path for a model and collection.
     */
    public function resolveForConversions(Model $model, MediaSlot $slot): string
    {
        $base_path = $this->resolve($model, $slot);

        return $base_path.'/'.self::conversionsFolder();
    }

    /**
     * Interpolate a path template with built-in and model attribute tokens.
     */
    public function interpolate(string $template, Model $model, string $collection_name = 'default'): string
    {
        $now = now();
        $model_key = $model->getKey();
        $modelIdentifier = is_string($model_key) || is_int($model_key)
            ? (string) $model_key
            : 'unsaved';

        $built_in = [
            'id' => $modelIdentifier,
            'uuid' => $modelIdentifier,
            'model_type' => Str::snake(class_basename($model)),
            'model_id' => $modelIdentifier,
            'collection' => $collection_name,
            'date' => $now->format('Y/m/d'),
            'year' => $now->format('Y'),
            'month' => $now->format('m'),
            'day' => $now->format('d'),
        ];

        $path = preg_replace_callback('/\{(\w+)\}/', function (array $matches) use ($model, $built_in) {
            $key = $matches[1];

            if (isset($built_in[$key])) {
                return $built_in[$key];
            }

            $value = $model->getAttribute($key);

            if ($value !== null) {
                return $this->sanitizeToken($this->normalizeValue($value));
            }

            return '';
        }, $template);

        return $this->sanitize((string) $path);
    }

    /* ---------------------------------------------------------------
     * Media-Based Path Building
     * ------------------------------------------------------------- */

    /**
     * Build the relative storage path for a media record.
     */
    public function mediaPath(Media $media): string
    {
        $parts = array_filter([self::rootFolder(), $media->folder, $media->hash]);

        return implode('/', $parts);
    }

    /**
     * Build the future relative storage path for a media record in a given folder.
     *
     * @param  Media  $media  Media record being planned
     * @param  string  $folder  Target clean folder without the root prefix
     * @return string Relative storage path for the media object
     */
    public function mediaPathForFolder(Media $media, string $folder): string
    {
        $parts = array_filter([self::rootFolder(), $this->normalizeFolder($folder), $media->hash]);

        return implode('/', $parts);
    }

    /**
     * Build the variation folder path for a media record.
     */
    public function variationFolder(Media $media): string
    {
        $baseFolder = self::storagePath($media->folder ?? '');
        $convFolder = self::conversionsFolder();

        return $baseFolder !== '' ? $baseFolder.'/'.$convFolder : $convFolder;
    }

    /**
     * Build the full relative path for a variation file.
     */
    public function variationPath(Media $media, string $variationFilename): string
    {
        return $this->variationFolder($media).'/'.$variationFilename;
    }

    /**
     * Build the future relative storage path for a variation in a given folder.
     *
     * @param  Media  $media  Parent media record being planned
     * @param  MediaImageVariation  $variation  Variation record being planned
     * @param  string  $folder  Target clean folder without the root prefix
     * @return string Relative storage path for the variation object
     */
    public function variationPathForFolder(Media $media, MediaImageVariation $variation, string $folder): string
    {
        $baseFolder = self::storagePath($this->normalizeFolder($folder));

        return implode('/', array_filter([
            $baseFolder,
            self::conversionsFolder(),
            $variation->getFilename(),
        ]));
    }

    /* ---------------------------------------------------------------
     * Static Path Utilities
     * ------------------------------------------------------------- */

    /**
     * Get the configured root folder prefix for all media storage paths.
     */
    public static function rootFolder(): string
    {
        return trim(MediaConfiguration::string('media.root_folder', ''), '/');
    }

    /**
     * Prepend the root folder to a given folder path (for pre-persist storage operations).
     */
    public static function storagePath(string $folder): string
    {
        $root = self::rootFolder();

        if ($root === '') {
            return $folder;
        }

        return $folder !== '' ? $root.'/'.$folder : $root;
    }

    /**
     * Get the configured conversions subfolder name.
     */
    public static function conversionsFolder(): string
    {
        $folder = config('media.conversions_folder');

        return is_string($folder) && $folder !== '' ? $folder : 'conversions';
    }

    /**
     * Assert that a path is safe (no traversal or null bytes).
     *
     * @throws MediaUploadException When path contains traversal or null bytes
     */
    public static function assertSafe(string $path): void
    {
        $decoded = str_replace('\\', '/', rawurldecode($path));

        if (str_contains($decoded, '..') || str_contains($decoded, "\0")) {
            throw new MediaUploadException(
                "Path traversal detected in media path: [{$path}].",
            );
        }
    }

    /* ---------------------------------------------------------------
     * Private Helpers
     * ------------------------------------------------------------- */

    /**
     * Normalize token values to path-safe strings.
     */
    private function normalizeValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Sanitize a single interpolated token value by stripping path-unsafe characters.
     */
    private function sanitizeToken(string $value): string
    {
        return str_replace(['..', '/', '\\', "\0"], '', $value);
    }

    /**
     * Clean up a path by collapsing double slashes, trimming, and rejecting traversal.
     *
     * @throws MediaUploadException
     */
    private function sanitize(string $path): string
    {
        $path = str_replace('\\', '/', rawurldecode($path));
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        $path = trim($path, '/');

        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new MediaUploadException(
                "Path traversal detected in resolved media path: [{$path}].",
            );
        }

        return $path;
    }
}
