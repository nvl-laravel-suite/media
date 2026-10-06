<?php

declare(strict_types=1);

namespace Nvl\Media\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Contracts\MediaUploadBuilder;
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
use Nvl\Media\Facades\Media as MediaFacade;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Support\Testing\FakeCalls;
use TypeError;

/**
 * Complete, explicitly scripted Media library with no storage or ingestion dependencies.
 *
 * @api
 */
final class FakeMediaLibrary implements MediaLibraryContract
{
    use FakeCalls;

    /** Create an isolated fake with an explicit fallback cloud disk. */
    public function __construct(private readonly string $cloudDisk = 's3') {}

    /** Install this fake in the supplied container and invalidate the Media facade root. */
    public static function fake(Container $container): self
    {
        $cloudDisk = 's3';

        if ($container->bound('config')) {
            /** @var Repository $configuration */
            $configuration = $container->make('config');
            $configuredDisk = $configuration->get('filesystems.cloud', 's3');
            $cloudDisk = is_string($configuredDisk) ? $configuredDisk : 's3';
        }

        $fake = new self($cloudDisk);
        $container->instance(MediaLibraryContract::class, $fake);
        MediaFacade::clearResolvedInstance(MediaLibraryContract::class);

        return $fake;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function add(
        Model&HasNvlMedia $owner,
        UploadedFile|string $file,
        ?string $slot = null,
    ): MediaUploadBuilder {
        return $this->builder('add', ['owner' => $owner, 'file' => $file, 'slot' => $slot], $owner, $slot, false);
    }

    /** Record the native invocation and consume its next scripted result. */
    public function copy(Model&HasNvlMedia $owner, UploadedFile|string $file): MediaUploadBuilder
    {
        return $this->builder('copy', ['owner' => $owner, 'file' => $file], $owner, null, true);
    }

    /** Record the native invocation and consume its next scripted result. */
    public function fromRequest(Model&HasNvlMedia $owner, string $key): MediaUploadBuilder
    {
        return $this->builder('fromRequest', ['owner' => $owner, 'key' => $key], $owner, null, false);
    }

    /** Record the native invocation and consume its next scripted result. */
    public function fromUrl(
        Model&HasNvlMedia $owner,
        string $url,
        string ...$allowedMimeTypes,
    ): MediaUploadBuilder {
        return $this->builder('fromUrl', ['owner' => $owner, 'url' => $url, 'allowedMimeTypes' => $allowedMimeTypes], $owner, null, false);
    }

    /** Record the native invocation and consume its next scripted result. */
    public function fromBase64(
        Model&HasNvlMedia $owner,
        string $payload,
        string ...$allowedMimeTypes,
    ): MediaUploadBuilder {
        return $this->builder('fromBase64', ['owner' => $owner, 'payload' => $payload, 'allowedMimeTypes' => $allowedMimeTypes], $owner, null, false);
    }

    /** Record the native invocation and consume its next scripted result. */
    public function fromString(Model&HasNvlMedia $owner, string $contents): MediaUploadBuilder
    {
        return $this->builder('fromString', ['owner' => $owner, 'contents' => $contents], $owner, null, false);
    }

    /**
     * Create an upload builder from generated binary content.
     */
    public function fromBinary(
        Model&HasNvlMedia $owner,
        string $contents,
        string $filename,
        string ...$allowedMimeTypes,
    ): MediaUploadBuilder {
        return $this->builder('fromBinary', ['owner' => $owner, 'contents' => $contents, 'filename' => $filename, 'allowedMimeTypes' => $allowedMimeTypes], $owner, null, false);
    }

    /** Record the native invocation and consume its next scripted result. */
    public function fromDisk(
        Model&HasNvlMedia $owner,
        string $key,
        ?string $disk = null,
    ): MediaUploadBuilder {
        return $this->builder('fromDisk', ['owner' => $owner, 'key' => $key, 'disk' => $disk], $owner, null, false);
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
        $result = $this->invoke('paginate', ['filters' => $filters, 'actor' => $actor, 'includeVariations' => $includeVariations]);
        if (! $result instanceof LengthAwarePaginator) {
            throw new TypeError('FakeMediaLibrary::paginate requires a scripted LengthAwarePaginator.');
        }

        /** @var LengthAwarePaginator<int, Media> $result */
        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function findOrFail(string $id, bool $includeVariations = true): Media
    {
        $result = $this->invoke('findOrFail', ['id' => $id, 'includeVariations' => $includeVariations]);
        if (! $result instanceof Media) {
            throw new TypeError('FakeMediaLibrary::findOrFail requires a scripted Media.');
        }

        return $result;
    }

    /**
     * Resolve a URL only for an existing row and canonical backing object.
     */
    public function urlIfExists(?Media $media, string $variation = ''): ?string
    {
        $result = $this->invoke('urlIfExists', ['media' => $media, 'variation' => $variation]);
        if ($result !== null && ! is_string($result)) {
            throw new TypeError('FakeMediaLibrary::urlIfExists requires a scripted string or null.');
        }

        return $result;
    }

    /**
     * @return Collection<int, MediaUsage>
     */
    public function usages(string $id): Collection
    {
        $result = $this->invoke('usages', ['id' => $id]);
        if (! $result instanceof Collection) {
            throw new TypeError('FakeMediaLibrary::usages requires a scripted Collection.');
        }

        /** @var Collection<int, MediaUsage> $result */
        return $result;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function attach(
        Media $media,
        Model&HasNvlMedia $owner,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): MediaAssociation {
        $result = $this->invoke('attach', ['media' => $media, 'owner' => $owner, 'collection' => $collection, 'locale' => $locale, 'order' => $order, 'metadata' => $metadata, 'dispatchVariations' => $dispatchVariations]);
        if (! $result instanceof MediaAssociation) {
            throw new TypeError('FakeMediaLibrary::attach requires a scripted MediaAssociation.');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function reuse(
        Media|string $media,
        Model&HasNvlMedia $owner,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): Media {
        $result = $this->invoke('reuse', ['media' => $media, 'owner' => $owner, 'collection' => $collection, 'locale' => $locale, 'order' => $order, 'metadata' => $metadata, 'dispatchVariations' => $dispatchVariations]);
        if (! $result instanceof Media) {
            throw new TypeError('FakeMediaLibrary::reuse requires a scripted Media.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function detach(
        Media|string $media,
        Model&HasNvlMedia $owner,
        ?string $collection = null,
    ): int {
        $result = $this->invoke('detach', ['media' => $media, 'owner' => $owner, 'collection' => $collection]);
        if (! is_int($result)) {
            throw new TypeError('FakeMediaLibrary::detach requires a scripted int.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function delete(Media|string $media, bool $force = false): bool
    {
        $result = $this->invoke('delete', ['media' => $media, 'force' => $force]);
        if (! is_bool($result)) {
            throw new TypeError('FakeMediaLibrary::delete requires a scripted bool.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function replace(Media|string $media, UploadedFile $file): Media
    {
        $result = $this->invoke('replace', ['media' => $media, 'file' => $file]);
        if (! $result instanceof Media) {
            throw new TypeError('FakeMediaLibrary::replace requires a scripted Media.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function rename(Media|string $media, string $filename): Media
    {
        $result = $this->invoke('rename', ['media' => $media, 'filename' => $filename]);
        if (! $result instanceof Media) {
            throw new TypeError('FakeMediaLibrary::rename requires a scripted Media.');
        }

        return $result;
    }

    /**
     * Relocate one media record and its variations to another disk.
     */
    public function relocate(
        Media|string $media,
        string $disk,
        MediaVisibility $visibility,
        ?int $expectedRevision = null,
    ): Media {
        $result = $this->invoke('relocate', ['media' => $media, 'disk' => $disk, 'visibility' => $visibility, 'expectedRevision' => $expectedRevision]);
        if (! $result instanceof Media) {
            throw new TypeError('FakeMediaLibrary::relocate requires a scripted Media.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function updateMetadata(Media|string $media, UpdateMediaPayload $data): Media
    {
        $result = $this->invoke('updateMetadata', ['media' => $media, 'data' => $data]);
        if (! $result instanceof Media) {
            throw new TypeError('FakeMediaLibrary::updateMetadata requires a scripted Media.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function generateVariation(
        Media $media,
        ConversionDefinition $definition,
        ?int $expectedRevision = null,
    ): ?MediaImageVariation {
        $result = $this->invoke('generateVariation', ['media' => $media, 'definition' => $definition, 'expectedRevision' => $expectedRevision]);
        if ($result !== null && ! $result instanceof MediaImageVariation) {
            throw new TypeError('FakeMediaLibrary::generateVariation requires a scripted MediaImageVariation or null.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function finalizeScan(Media|string $media, MediaScanResultData $result): Media
    {
        $result = $this->invoke('finalizeScan', ['media' => $media, 'result' => $result]);
        if (! $result instanceof Media) {
            throw new TypeError('FakeMediaLibrary::finalizeScan requires a scripted Media.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function initiateMultipart(
        InitiateMultipartUploadData $data,
        MediaActorData $actor,
    ): MultipartUploadSessionData {
        $result = $this->invoke('initiateMultipart', ['data' => $data, 'actor' => $actor]);
        if (! $result instanceof MultipartUploadSessionData) {
            throw new TypeError('FakeMediaLibrary::initiateMultipart requires a scripted MultipartUploadSessionData.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function signMultipartPart(
        SignMultipartPartData $part,
        MediaActorData $actor,
    ): SignedMultipartPartData {
        $result = $this->invoke('signMultipartPart', ['part' => $part, 'actor' => $actor]);
        if (! $result instanceof SignedMultipartPartData) {
            throw new TypeError('FakeMediaLibrary::signMultipartPart requires a scripted SignedMultipartPartData.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function completeMultipart(
        CompleteMultipartUploadData $completion,
        MediaActorData $actor,
    ): Media {
        $result = $this->invoke('completeMultipart', ['completion' => $completion, 'actor' => $actor]);
        if (! $result instanceof Media) {
            throw new TypeError('FakeMediaLibrary::completeMultipart requires a scripted Media.');
        }

        return $result;
    }

    /** Record the native invocation and consume its next scripted result. */
    public function abortMultipart(string $uploadId, MediaActorData $actor): void
    {
        $this->invoke('abortMultipart', ['uploadId' => $uploadId, 'actor' => $actor]);
    }

    /** Record the native invocation and consume its next scripted result. */
    public function allows(
        Authenticatable $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool {
        $result = $this->invoke('allows', ['actor' => $actor, 'ability' => $ability, 'media' => $media, 'owner' => $owner]);
        if (! is_bool($result)) {
            throw new TypeError('FakeMediaLibrary::allows requires a scripted bool.');
        }

        return $result;
    }

    /**
     * Declare exactly the native Media library methods.
     *
     * @return list<string>
     *
     * @internal
     */
    protected function fakeMethods(): array
    {
        return [
            'add',
            'copy',
            'fromRequest',
            'fromUrl',
            'fromBase64',
            'fromString',
            'fromBinary',
            'fromDisk',
            'paginate',
            'findOrFail',
            'urlIfExists',
            'usages',
            'attach',
            'reuse',
            'detach',
            'delete',
            'replace',
            'rename',
            'relocate',
            'updateMetadata',
            'generateVariation',
            'finalizeScan',
            'initiateMultipart',
            'signMultipartPart',
            'completeMultipart',
            'abortMultipart',
            'allows',
        ];
    }

    /**
     * Return the scripted native builder and initialize fake-specific context once.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function builder(
        string $method,
        array $arguments,
        Model&HasNvlMedia $owner,
        ?string $slot,
        bool $preserveOriginal,
    ): MediaUploadBuilder {
        $builder = $this->invoke($method, $arguments);
        if (! $builder instanceof MediaUploadBuilder) {
            throw new TypeError('FakeMediaLibrary upload sources require a scripted MediaUploadBuilder.');
        }

        if ($builder instanceof FakeMediaUploadBuilder) {
            $builder->initialize($method, $arguments, $owner, $slot, $preserveOriginal, $this->cloudDisk);
        }

        return $builder;
    }
}
