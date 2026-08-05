<?php

declare(strict_types=1);

namespace Nvl\Media;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Contracts\MediaLibraryContract;
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
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaAccessService;
use Nvl\Media\Services\MediaModelInteractionService;
use Nvl\Media\Services\MediaMultipartService;
use Nvl\Media\Services\MediaMutationService;
use Nvl\Media\Services\MediaQueryService;

/**
 * Thin consumer API that delegates to the package's focused services and actions.
 */
final readonly class MediaLibrary implements MediaLibraryContract
{
    public function __construct(
        private MediaModelInteractionService $interactions,
        private MediaQueryService $queries,
        private MediaMutationService $mutations,
        private MediaMultipartService $multipart,
        private MediaAccessService $access,
    ) {}

    public function add(
        Model&HasMedia $owner,
        UploadedFile|string $file,
        ?string $slot = null,
    ): MediaAdder {
        return $this->interactions->addMedia($owner, $file, $slot);
    }

    public function copy(Model&HasMedia $owner, UploadedFile|string $file): MediaAdder
    {
        return $this->interactions->copyMedia($owner, $file);
    }

    public function fromRequest(Model&HasMedia $owner, string $key): MediaAdder
    {
        return $this->interactions->addMediaFromRequest($owner, $key);
    }

    public function fromUrl(
        Model&HasMedia $owner,
        string $url,
        string ...$allowedMimeTypes,
    ): MediaAdder {
        return $this->interactions->addMediaFromUrl($owner, $url, ...$allowedMimeTypes);
    }

    public function fromBase64(
        Model&HasMedia $owner,
        string $payload,
        string ...$allowedMimeTypes,
    ): MediaAdder {
        return $this->interactions->addMediaFromBase64(
            $owner,
            $payload,
            ...$allowedMimeTypes,
        );
    }

    public function fromString(Model&HasMedia $owner, string $contents): MediaAdder
    {
        return $this->interactions->addMediaFromString($owner, $contents);
    }

    public function fromDisk(
        Model&HasMedia $owner,
        string $key,
        ?string $disk = null,
    ): MediaAdder {
        return $this->interactions->addMediaFromDisk($owner, $key, $disk);
    }

    /**
     * @param  MediaFilter|array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Media>
     */
    public function paginate(
        MediaFilter|array $filters = [],
        ?Authenticatable $actor = null,
        bool $includeVariations = false,
    ): LengthAwarePaginator {
        return $this->queries->index(
            $filters instanceof MediaFilter ? $filters : MediaFilter::from($filters),
            $actor,
            $includeVariations,
        );
    }

    public function findOrFail(string $id, bool $includeVariations = true): Media
    {
        return $this->queries->show($id, $includeVariations);
    }

    /**
     * @return Collection<int, MediaUsage>
     */
    public function usages(string $id): Collection
    {
        return $this->queries->usages($id);
    }

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
    ): MediaAssociation {
        return $this->interactions->attachMedia(
            $media,
            $owner,
            $collection,
            $locale,
            $order,
            $metadata,
            $dispatchVariations,
        );
    }

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
    ): Media {
        return $this->interactions->reusePublicMedia(
            $owner,
            $media,
            $collection,
            $locale,
            $order,
            $metadata,
            $dispatchVariations,
        );
    }

    public function detach(
        Media|string $media,
        Model&HasMedia $owner,
        ?string $collection = null,
    ): int {
        return $this->interactions->detachMedia($media, $owner, $collection);
    }

    public function delete(Media|string $media, bool $force = false): bool
    {
        return $this->mutations->delete($media, $force);
    }

    public function replace(Media|string $media, UploadedFile $file): Media
    {
        return $this->mutations->replace($media, $file);
    }

    public function rename(Media|string $media, string $filename): Media
    {
        return $this->mutations->rename($media, $filename);
    }

    public function updateMetadata(Media|string $media, UpdateMediaPayload $data): Media
    {
        return $this->mutations->update($media, $data);
    }

    public function generateVariation(
        Media $media,
        ConversionDefinition $definition,
        ?int $expectedRevision = null,
    ): ?MediaImageVariation {
        return $this->mutations->generateVariation($media, $definition, $expectedRevision);
    }

    public function finalizeScan(Media|string $media, MediaScanResultData $result): Media
    {
        return $this->mutations->finalizeScan($media, $result);
    }

    public function initiateMultipart(
        InitiateMultipartUploadData $data,
        MediaActorData $actor,
    ): MultipartUploadSessionData {
        return $this->multipart->initiate($data, $actor);
    }

    public function signMultipartPart(
        SignMultipartPartData $part,
        MediaActorData $actor,
    ): SignedMultipartPartData {
        return $this->multipart->signPart($part, $actor);
    }

    public function completeMultipart(
        CompleteMultipartUploadData $completion,
        MediaActorData $actor,
    ): Media {
        return $this->multipart->complete($completion, $actor);
    }

    public function abortMultipart(string $uploadId, MediaActorData $actor): void
    {
        $this->multipart->abort($uploadId, $actor);
    }

    public function allows(
        Authenticatable $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool {
        return $this->access->allows($actor, $ability, $media, $owner);
    }
}
