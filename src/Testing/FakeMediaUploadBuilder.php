<?php

declare(strict_types=1);

namespace Nvl\Media\Testing;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaUploadBuilder;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Models\Media;
use Nvl\Support\Testing\FakeCalls;
use Nvl\Support\Testing\FakeExpectationFailed;
use TypeError;

/**
 * Records fluent upload requests and returns only explicitly scripted media handles.
 *
 * @api
 */
final class FakeMediaUploadBuilder implements MediaUploadBuilder
{
    use FakeCalls;

    private ?string $pendingSlot = null;

    private ?string $collectionOverride = null;

    private ?string $fileName = null;

    private ?Closure $fileNameSanitizer = null;

    private bool $preserveOriginal = false;

    /** @var array<string, mixed> */
    private array $customProperties = [];

    /** @var array<int, string> */
    private array $tags = [];

    private ?string $locale = null;

    private ?int $order = null;

    private ?string $diskOverride = null;

    private ?string $folderOverride = null;

    private ?bool $publicOverride = null;

    /** @var array<string, ConversionDefinition|array<string, mixed>>|null */
    private ?array $variationOverrides = null;

    private bool $skipVariations = false;

    /** @var array<string, mixed> */
    private array $associationMeta = [];

    private bool $allowDuplicates = false;

    private ?string $uploaderId = null;

    private ?string $uploaderType = null;

    /** @var array{source: array{method: string, arguments: array<string, mixed>}, owner: array{model: Model&HasNvlMedia, id: mixed, type: string}}|null */
    private ?array $context = null;

    /** Create an isolated builder with an explicit fallback cloud disk. */
    public function __construct(private string $cloudDisk = 's3') {}

    /**
     * Capture the original library invocation once without resolving its source.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @internal
     */
    public function initialize(
        string $method,
        array $arguments,
        Model&HasNvlMedia $owner,
        ?string $pendingSlot,
        bool $preserveOriginal,
        string $cloudDisk,
    ): void {
        if ($this->context !== null) {
            throw new FakeExpectationFailed('A fake media upload builder can belong to only one library invocation.');
        }

        unset($arguments['owner']);
        $this->context = [
            'source' => ['method' => $method, 'arguments' => $arguments],
            'owner' => ['model' => $owner, 'id' => $owner->getKey(), 'type' => $owner->getMorphClass()],
        ];
        $this->pendingSlot = $pendingSlot;
        $this->preserveOriginal = $preserveOriginal;
        $this->cloudDisk = $cloudDisk;
    }

    /** Configure the upload request. */
    public function usingSlot(string $slot): static
    {
        $this->pendingSlot = $slot;

        return $this;
    }

    /** Configure the upload request. */
    public function preservingOriginal(bool $preserve = true): static
    {
        $this->preserveOriginal = $preserve;

        return $this;
    }

    /** Configure the upload request. */
    public function usingFileName(string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    /** Configure the upload request. */
    public function sanitizingFileName(Closure $sanitizer): static
    {
        $this->fileNameSanitizer = $sanitizer;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function withCustomProperties(array $properties): static
    {
        $this->customProperties = array_merge($this->customProperties, $properties);

        return $this;
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function withTags(array $tags): static
    {
        $this->tags = array_merge($this->tags, $tags);

        return $this;
    }

    /** Configure the upload request. */
    public function tags(string ...$tags): static
    {
        $this->tags = array_merge($this->tags, array_values($tags));

        return $this;
    }

    /** Configure the upload request. */
    public function toLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /** Configure the upload request. */
    public function withOrder(int $order): static
    {
        $this->order = $order;

        return $this;
    }

    /** Configure the upload request. */
    public function toDisk(string $disk): static
    {
        $this->diskOverride = $disk;

        return $this;
    }

    /** Configure the upload request. */
    public function toFolder(string $folder): static
    {
        $this->folderOverride = $folder;

        return $this;
    }

    /** Configure the upload request. */
    public function asPublic(bool $public = true): static
    {
        $this->publicOverride = $public;

        return $this;
    }

    /**
     * @param  array<string, ConversionDefinition|array<string, mixed>>  $variations
     */
    public function withVariations(array $variations): static
    {
        $this->variationOverrides = $variations;

        return $this;
    }

    /** Configure the upload request. */
    public function withoutVariations(): static
    {
        $this->skipVariations = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withAssociationMeta(array $meta): static
    {
        $this->associationMeta = array_merge($this->associationMeta, $meta);

        return $this;
    }

    /** Configure the upload request. */
    public function allowingDuplicates(bool $allow = true): static
    {
        $this->allowDuplicates = $allow;

        return $this;
    }

    /**
     * Attribute the upload to an explicit authenticatable model.
     *
     * @throws InvalidArgumentException When the uploader has no string or integer identifier
     */
    public function uploadedBy(Model&Authenticatable $uploader): static
    {
        $identifier = $uploader->getAuthIdentifier();

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new InvalidArgumentException(
                'Explicit media uploader must have a string or integer authentication identifier.',
            );
        }

        $this->uploaderId = (string) $identifier;
        $this->uploaderType = $uploader->getMorphClass();

        return $this;
    }

    /**
     * Fluent setter for the collection name stored in the pivot.
     */
    public function toCollection(string $collection): static
    {
        $this->collectionOverride = $collection;

        return $this;
    }

    /** Record one slot attempt and consume its next scripted media result. */
    public function slot(?string $name = null, ?string $disk = null): Media
    {
        return $this->complete('slot', ['name' => $name, 'disk' => $disk], $name, $disk);
    }

    /** Record one cloud-slot attempt using this builder's installation context. */
    public function slotOnCloudDisk(?string $name = null): Media
    {
        return $this->complete('slotOnCloudDisk', ['name' => $name], $name, $this->cloudDisk);
    }

    /** Record one upload attempt without dispatching another terminal call. */
    public function upload(): Media
    {
        return $this->complete('upload', [], null, null);
    }

    /**
     * Declare the native terminal script names.
     *
     * @return list<string>
     *
     * @internal
     */
    protected function fakeMethods(): array
    {
        return ['slot', 'slotOnCloudDisk', 'upload'];
    }

    /**
     * Snapshot the full request before the script returns, throws, or is exhausted.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function complete(string $method, array $arguments, ?string $name, ?string $disk): Media
    {
        if ($this->context === null) {
            throw new FakeExpectationFailed('Initialize the fake media upload builder through a library source method before uploading.');
        }

        if ($disk !== null) {
            $this->diskOverride = $disk;
        }

        $slot = $name ?? $this->pendingSlot ?? 'default';

        $result = $this->invoke($method, [
            'arguments' => $arguments,
            'source' => $this->context['source'],
            'owner' => $this->context['owner'],
            'options' => [
                'slot' => $slot,
                'pendingSlot' => $this->pendingSlot,
                'collection' => $this->collectionOverride ?? $slot,
                'fileName' => $this->fileName,
                'fileNameSanitizer' => $this->fileNameSanitizer,
                'preserveOriginal' => $this->preserveOriginal,
                'customProperties' => $this->customProperties,
                'tags' => $this->tags,
                'locale' => $this->locale,
                'order' => $this->order,
                'disk' => $this->diskOverride,
                'folder' => $this->folderOverride,
                'public' => $this->publicOverride,
                'variations' => $this->variationOverrides,
                'skipVariations' => $this->skipVariations,
                'associationMeta' => array_merge($this->associationMeta, ['slot' => $slot]),
                'allowDuplicates' => $this->allowDuplicates,
                'uploaderId' => $this->uploaderId,
                'uploaderType' => $this->uploaderType,
            ],
        ]);
        if (! $result instanceof Media) {
            throw new TypeError('FakeMediaUploadBuilder terminals require a scripted Media.');
        }

        return $result;
    }
}
