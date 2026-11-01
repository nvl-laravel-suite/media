<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaDeliveryMode;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Support\MediaActors;
use Nvl\Media\Support\MediaAssetUrl;
use Nvl\Support\Enums\SystemActor;

/**
 * MediaUrlResolver: injected URL boundary for cache-aware media and variation URL generation.
 *
 * @api
 */
final class MediaUrlResolver
{
    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaFileExistence $existence,
        private readonly MediaAccessService $access,
    ) {}

    /**
     * Resolve the configured or explicitly admitted URL without issuing private capabilities.
     *
     * @param  string  $variation  Variation label, or empty for original
     */
    public function forMedia(Media $media, string $variation = '', ?MediaDeliveryMode $mode = null): string
    {
        return MediaAssetUrl::forMedia(
            $media,
            $variation,
            $this->existenceChecker(),
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
            $mode,
        );
    }

    /**
     * Resolve a URL only when the media and its canonical binary exist.
     */
    public function forExistingMedia(?Media $media, string $variation = '', ?MediaDeliveryMode $mode = null): ?string
    {
        if (! $media instanceof Media || ! $this->existence->exists($media->disk, $media->buildPath())) {
            return null;
        }

        return $this->forMedia($media, $variation, $mode);
    }

    /**
     * Get URL for a variation model, falling back to parent media if file is missing.
     */
    public function forVariation(MediaImageVariation $variation, ?MediaDeliveryMode $mode = null): string
    {
        return MediaAssetUrl::forVariation(
            $variation,
            $this->existenceChecker(),
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
            $mode,
        );
    }

    /**
     * Resolve a delivery URL with optional named variation parameters.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildUrl(
        Media $media,
        array $parameters = [],
        ?MediaDeliveryMode $mode = null,
    ): string {
        return MediaAssetUrl::buildUrl(
            $media,
            $parameters,
            null,
            null,
            $this->existenceChecker(),
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
            $mode,
        );
    }

    /**
     * Build a centralized public asset URL.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function publicUrl(Media $media, array $parameters = [], ?MediaDeliveryMode $mode = null): string
    {
        return MediaAssetUrl::publicUrl($media, $parameters, $this->existenceChecker(), $this->diskUrlBuilder(), $mode);
    }

    /**
     * Build a centralized signed private asset URL.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function signedUrl(
        Authenticatable|SystemActor $actor,
        Media $media,
        DateTimeInterface $expiration,
        array $parameters = [],
    ): string {
        $media = $this->authorizeDownload($actor, $media, is_string($parameters['v'] ?? null) ? $parameters['v'] : null);

        return MediaAssetUrl::privateUrl(
            $media,
            $parameters,
            $expiration,
            null,
            $this->existenceChecker(),
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
        );
    }

    /**
     * Authorize Download and issue a provider URL with an explicit bounded expiration.
     */
    public function temporaryUrl(Authenticatable|SystemActor $actor, Media $media, DateTimeInterface $expiration, string $variation = ''): string
    {
        $media = $this->authorizeDownload($actor, $media, $variation);

        return MediaAssetUrl::temporaryUrl(
            $media,
            $expiration,
            $variation,
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
        );
    }

    /**
     * Resolve the canonical asset and its current policy before granting byte access.
     * Carried model attributes never determine the signed object's storage identity.
     */
    private function authorizeDownload(Authenticatable|SystemActor $actor, Media $media, ?string $variation = null): Media
    {
        $actor = MediaActors::assert($actor);
        $canonical = MediaModelRegistry::query()->useWritePdo()->findOrFail($media->id);
        $this->access->authorize($actor, MediaAbility::Download, $canonical);

        if ($variation !== null && $variation !== '') {
            $canonical->setRelation('imageVariations', $canonical->imageVariations()->chaperone('media')
                ->useWritePdo()->where('label', $variation)->get());
        }

        return $canonical;
    }

    /**
     * Get the absolute filesystem path for a media record or variation.
     */
    public function path(Media $media, string $variation = ''): string
    {
        return MediaAssetUrl::path($media, $variation, $this->localPathResolver());
    }

    /**
     * @return Closure(string, string): bool
     */
    private function existenceChecker(): Closure
    {
        return fn (string $disk, string $path): bool => $this->existence->exists($disk, $path);
    }

    /**
     * @return Closure(string, string): string
     */
    private function diskUrlBuilder(): Closure
    {
        return fn (string $disk, string $path): string => $this->disks->url($disk, $path);
    }

    /**
     * @return Closure(string, string, DateTimeInterface): string
     */
    private function temporaryUrlBuilder(): Closure
    {
        return fn (string $disk, string $path, DateTimeInterface $expiration): string => $this->disks->temporaryUrl(
            $disk,
            $path,
            $expiration,
        );
    }

    /**
     * @return Closure(string, string): string
     */
    private function localPathResolver(): Closure
    {
        return fn (string $disk, string $path): string => $this->disks->localPath($disk, $path);
    }
}
