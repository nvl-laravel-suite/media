<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Models\Media;

/**
 * Consumer-facing fluent upload request with the native MediaAdder terminals.
 *
 * @api
 */
interface MediaUploadBuilder
{
    /** Configure the upload request. */
    public function usingSlot(string $slot): static;

    /** Configure the upload request. */
    public function preservingOriginal(bool $preserve = true): static;

    /** Configure the upload request. */
    public function usingFileName(string $fileName): static;

    /** Configure the upload request. */
    public function sanitizingFileName(Closure $sanitizer): static;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function withCustomProperties(array $properties): static;

    /**
     * @param  array<int, string>  $tags
     */
    public function withTags(array $tags): static;

    /** Configure the upload request. */
    public function tags(string ...$tags): static;

    /** Configure the upload request. */
    public function toLocale(string $locale): static;

    /** Configure the upload request. */
    public function withOrder(int $order): static;

    /** Configure the upload request. */
    public function toDisk(string $disk): static;

    /** Configure the upload request. */
    public function toFolder(string $folder): static;

    /** Configure the upload request. */
    public function asPublic(bool $public = true): static;

    /**
     * @param  array<string, ConversionDefinition|array<string, mixed>>  $variations
     */
    public function withVariations(array $variations): static;

    /** Configure the upload request. */
    public function withoutVariations(): static;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withAssociationMeta(array $meta): static;

    /** Configure the upload request. */
    public function allowingDuplicates(bool $allow = true): static;

    /**
     * Attribute the upload to an explicit authenticatable model.
     *
     * @throws InvalidArgumentException When the uploader has no string or integer identifier
     */
    public function uploadedBy(Model&Authenticatable $uploader): static;

    /**
     * Fluent setter for the collection name stored in the pivot.
     */
    public function toCollection(string $collection): static;

    /**
     * Terminal method: upload and attach the file to the resolved slot.
     *
     * @param  string|null  $name  Slot name (falls back to pendingSlot, then 'default')
     * @param  string|null  $disk  Override disk for this upload
     */
    public function slot(?string $name = null, ?string $disk = null): Media;

    /** Upload and attach the requested media. */
    public function slotOnCloudDisk(?string $name = null): Media;

    /** Upload and attach the requested media. */
    public function upload(): Media;
}
