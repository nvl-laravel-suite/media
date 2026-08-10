<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Contracts\ReusePublicMediaContract;
use Nvl\Media\Contracts\UploadMediaContract;
use Nvl\Media\MediaAdder;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/**
 * Builds model-facing media adders from supported source inputs.
 */
final readonly class MediaModelInteractionService
{
    public function __construct(
        private Request $request,
        private MediaSourceResolver $sourceResolver,
        private MediaLifecycleService $lifecycleService,
        private ImageOptimizationService $imageOptimizationService,
        private UploadMediaContract $uploadMediaAction,
        private AttachMediaContract $attachMediaAction,
        private DetachMediaContract $detachMediaAction,
        private ReusePublicMediaContract $reusePublicMediaAction,
        private MediaMutationLock $mutationLock,
        private MediaOwnedSourceLifecycle $ownedSourceLifecycle,
        private MediaTemporaryFileRegistry $temporaryFiles,
    ) {}

    /**
     * Build a MediaAdder for the given model and source.
     */
    public function newAdder(Model&HasMedia $model, UploadedFile|string $file): MediaAdder
    {
        return new MediaAdder(
            model: $model,
            file: $file,
            imageOptimizationService: $this->imageOptimizationService,
            uploadMediaAction: $this->uploadMediaAction,
            attachMediaAction: $this->attachMediaAction,
            lifecycleService: $this->lifecycleService,
            mutationLock: $this->mutationLock,
            ownedSourceLifecycle: $this->ownedSourceLifecycle,
            temporaryFiles: $this->temporaryFiles,
        );
    }

    /**
     * Build an adder with an optional default slot.
     */
    public function addMedia(
        Model&HasMedia $model,
        UploadedFile|string $file,
        ?string $slot = null,
    ): MediaAdder {
        $adder = $this->newAdder($model, $file);

        return $slot === null ? $adder : $adder->usingSlot($slot);
    }

    /**
     * Create a preserving-original adder.
     */
    public function copyMedia(Model&HasMedia $model, UploadedFile|string $file): MediaAdder
    {
        return $this->newAdder($model, $file)->preservingOriginal();
    }

    /**
     * Attach an existing media record to a model.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function attachMedia(
        Media $media,
        Model $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): MediaAssociation {
        return $this->attachMediaAction->execute(
            media: $media,
            model: $model,
            collection: $collection,
            locale: $locale,
            order: $order,
            metadata: $metadata,
            dispatchVariations: $dispatchVariations,
        );
    }

    /**
     * Detach an existing media record from a model.
     */
    public function detachMedia(
        Media|string $media,
        Model $model,
        ?string $collection = null,
    ): int {
        return $this->detachMediaAction->execute($media, $model, $collection);
    }

    /**
     * Determine whether a model delete event is preserving a soft-deleted owner.
     */
    public function ownerIsSoftDeleting(Model $model): bool
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)
            || ! method_exists($model, 'isForceDeleting')) {
            return false;
        }

        return ! $model->isForceDeleting();
    }

    /**
     * Create an adder from a request file key.
     */
    public function addMediaFromRequest(Model&HasMedia $model, string $key): MediaAdder
    {
        return $this->newAdder($model, $this->sourceResolver->fromRequest($this->request, $key));
    }

    /**
     * Create an adder from a remote URL.
     */
    public function addMediaFromUrl(Model&HasMedia $model, string $url, string ...$allowedMimeTypes): MediaAdder
    {
        return $this->newAdder($model, $this->sourceResolver->fromUrl($url, ...$allowedMimeTypes));
    }

    /**
     * Create an adder from a base64 payload.
     */
    public function addMediaFromBase64(Model&HasMedia $model, string $base64data, string ...$allowedMimeTypes): MediaAdder
    {
        return $this->newAdder($model, $this->sourceResolver->fromBase64($base64data, ...$allowedMimeTypes));
    }

    /**
     * Create an adder from a raw string source.
     */
    public function addMediaFromString(Model&HasMedia $model, string $text): MediaAdder
    {
        return $this->newAdder($model, $this->sourceResolver->fromString($text));
    }

    /**
     * Create an adder from generated binary content with byte-detected MIME.
     */
    public function addMediaFromBinary(
        Model&HasMedia $model,
        string $contents,
        string $filename,
        string ...$allowedMimeTypes,
    ): MediaAdder {
        return $this->newAdder(
            $model,
            $this->sourceResolver->fromBinary($contents, $filename, ...$allowedMimeTypes),
        );
    }

    /**
     * Create an adder from an existing disk object.
     */
    public function addMediaFromDisk(Model&HasMedia $model, string $key, ?string $disk = null): MediaAdder
    {
        return $this->newAdder($model, $this->sourceResolver->fromDisk($key, $disk));
    }

    /**
     * Reuse an existing public asset without duplicating its stored file.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function reusePublicMedia(
        Model&HasMedia $model,
        Media|string $media,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): Media {
        $association = $this->reusePublicMediaAction->execute(
            media: $media,
            model: $model,
            collection: $collection,
            locale: $locale,
            order: $order,
            metadata: $metadata,
            dispatchVariations: $dispatchVariations,
        );

        return $association->media()->firstOrFail();
    }
}
