<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Data\Display\MediaUsage;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Data\MediaScanResultData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\InitiateMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;
use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\MediaAdder;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;

/**
 * Consumer-facing boundary for common media operations.
 *
 * Advanced consumers may continue injecting the focused action and service
 * contracts directly. This contract exists to provide one stable, replaceable
 * boundary for the Media facade and application-level orchestration.
 */
interface MediaLibraryContract
{
    public function add(
        Model&HasMedia $owner,
        UploadedFile|string $file,
        ?string $slot = null,
    ): MediaAdder;

    public function copy(Model&HasMedia $owner, UploadedFile|string $file): MediaAdder;

    public function fromRequest(Model&HasMedia $owner, string $key): MediaAdder;

    public function fromUrl(
        Model&HasMedia $owner,
        string $url,
        string ...$allowedMimeTypes,
    ): MediaAdder;

    public function fromBase64(
        Model&HasMedia $owner,
        string $payload,
        string ...$allowedMimeTypes,
    ): MediaAdder;

    public function fromString(Model&HasMedia $owner, string $contents): MediaAdder;

    /**
     * Create an upload builder from generated binary content.
     */
    public function fromBinary(
        Model&HasMedia $owner,
        string $contents,
        string $filename,
        string ...$allowedMimeTypes,
    ): MediaAdder;

    public function fromDisk(
        Model&HasMedia $owner,
        string $key,
        ?string $disk = null,
    ): MediaAdder;

    /**
     * @param  MediaFilter|array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Media>
     */
    public function paginate(
        MediaFilter|array $filters = [],
        ?Authenticatable $actor = null,
        bool $includeVariations = false,
    ): LengthAwarePaginator;

    public function findOrFail(string $id, bool $includeVariations = true): Media;

    /**
     * Resolve a URL only for an existing row and canonical backing object.
     */
    public function urlIfExists(?Media $media, string $variation = ''): ?string;

    /**
     * @return Collection<int, MediaUsage>
     */
    public function usages(string $id): Collection;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function attach(
        Media $media,
        Model&HasMedia $owner,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): MediaAssociation;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function reuse(
        Media|string $media,
        Model&HasMedia $owner,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): Media;

    public function detach(
        Media|string $media,
        Model&HasMedia $owner,
        ?string $collection = null,
    ): int;

    public function delete(Media|string $media, bool $force = false): bool;

    public function replace(Media|string $media, UploadedFile $file): Media;

    public function rename(Media|string $media, string $filename): Media;

    /**
     * Relocate one media record and its variations to another disk.
     */
    public function relocate(
        Media|string $media,
        string $disk,
        MediaVisibility $visibility,
        ?int $expectedRevision = null,
    ): Media;

    public function updateMetadata(Media|string $media, UpdateMediaPayload $data): Media;

    public function generateVariation(
        Media $media,
        ConversionDefinition $definition,
        ?int $expectedRevision = null,
    ): ?MediaImageVariation;

    public function finalizeScan(Media|string $media, MediaScanResultData $result): Media;

    public function initiateMultipart(
        InitiateMultipartUploadData $data,
        MediaActorData $actor,
    ): MultipartUploadSessionData;

    public function signMultipartPart(
        SignMultipartPartData $part,
        MediaActorData $actor,
    ): SignedMultipartPartData;

    public function completeMultipart(
        CompleteMultipartUploadData $completion,
        MediaActorData $actor,
    ): Media;

    public function abortMultipart(string $uploadId, MediaActorData $actor): void;

    public function allows(
        Authenticatable $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool;
}
