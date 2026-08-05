<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Closure;
use DateTimeInterface;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Support\MediaAssetUrl;

/** MediaUrlResolver: injected URL boundary for cache-aware media and variation URL generation. */
final class MediaUrlResolver
{
    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaFileExistence $existence,
    ) {}

    /**
     * Get the public or temporary URL for a media record or one of its variations.
     *
     * @param  string  $variation  Variation label, or empty for original
     */
    public function forMedia(Media $media, string $variation = ''): string
    {
        return MediaAssetUrl::forMedia(
            $media,
            $variation,
            $this->existenceChecker(),
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
        );
    }

    /**
     * Get URL for a variation model, falling back to parent media if file is missing.
     */
    public function forVariation(MediaImageVariation $variation): string
    {
        return MediaAssetUrl::forVariation(
            $variation,
            $this->existenceChecker(),
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
        );
    }

    /**
     * Build a centralized public or private URL with optional named variation parameters.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function buildUrl(
        Media $media,
        array $parameters = [],
        ?DateTimeInterface $expiration = null,
        ?string $owner = null,
    ): string {
        return MediaAssetUrl::buildUrl(
            $media,
            $parameters,
            $expiration,
            $owner,
            $this->existenceChecker(),
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
        );
    }

    /**
     * Build a centralized public asset URL.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function publicUrl(Media $media, array $parameters = []): string
    {
        return MediaAssetUrl::publicUrl($media, $parameters, $this->existenceChecker(), $this->diskUrlBuilder());
    }

    /**
     * Build a centralized signed private asset URL.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public function privateUrl(
        Media $media,
        array $parameters = [],
        ?DateTimeInterface $expiration = null,
        ?string $owner = null,
    ): string {
        return MediaAssetUrl::privateUrl(
            $media,
            $parameters,
            $expiration,
            $owner,
            $this->existenceChecker(),
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
        );
    }

    /**
     * Generate a temporary signed URL.
     */
    public function temporaryUrl(Media $media, DateTimeInterface $expiration, string $variation = ''): string
    {
        return MediaAssetUrl::temporaryUrl(
            $media,
            $expiration,
            $variation,
            $this->diskUrlBuilder(),
            $this->temporaryUrlBuilder(),
        );
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
