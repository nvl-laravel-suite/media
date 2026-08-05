<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use RuntimeException;
use Throwable;

/** Canonical URL and path rules shared by models and the injected URL resolver. */
final class MediaAssetUrl
{
    /** @var list<string> */
    private const array SUPPORTED_ASSET_PARAMETERS = ['v'];

    /**
     * Resolve a media URL using the same delivery rules as the injected resolver.
     *
     * @param  Closure(string, string): bool|null  $exists
     * @param  Closure(string, string): string|null  $diskUrl
     * @param  Closure(string, string, DateTimeInterface): string|null  $temporaryUrl
     */
    public static function forMedia(
        Media $media,
        string $variation = '',
        ?Closure $exists = null,
        ?Closure $diskUrl = null,
        ?Closure $temporaryUrl = null,
    ): string {
        self::assertAvailable($media);

        if (! $media->is_public) {
            $parameters = $variation !== '' ? ['v' => $variation] : [];

            return self::privateUrl(
                $media,
                $parameters,
                null,
                null,
                $exists,
                $diskUrl,
                $temporaryUrl,
            );
        }

        if ($variation !== '') {
            return self::forVariationLabel($media, $variation, $exists, $diskUrl, $temporaryUrl);
        }

        return self::publicUrl($media, [], $exists, $diskUrl);
    }

    /**
     * Resolve a variation URL, falling back to the parent media when the file is missing.
     *
     * @param  Closure(string, string): bool|null  $exists
     * @param  Closure(string, string): string|null  $diskUrl
     * @param  Closure(string, string, DateTimeInterface): string|null  $temporaryUrl
     */
    public static function forVariation(
        MediaImageVariation $variation,
        ?Closure $exists = null,
        ?Closure $diskUrl = null,
        ?Closure $temporaryUrl = null,
    ): string {
        /** @var Media $media */
        $media = $variation->media;
        self::assertAvailable($media);

        if (! self::isCurrentAvailableVariation($media, $variation)) {
            return self::forMedia($media, '', $exists, $diskUrl, $temporaryUrl);
        }

        $path = $variation->getPath();

        if (! self::objectExists($media->disk, $path, $exists)) {
            return self::forMedia($media, '', $exists, $diskUrl, $temporaryUrl);
        }

        return self::buildUrl($media, ['v' => $variation->label], null, null, $exists, $diskUrl, $temporaryUrl);
    }

    /**
     * Build a centralized public or private URL with optional named variation parameters.
     *
     * @param  array<string, scalar|null>  $parameters
     * @param  Closure(string, string): bool|null  $exists
     * @param  Closure(string, string): string|null  $diskUrl
     * @param  Closure(string, string, DateTimeInterface): string|null  $temporaryUrl
     */
    public static function buildUrl(
        Media $media,
        array $parameters = [],
        ?DateTimeInterface $expiration = null,
        ?string $owner = null,
        ?Closure $exists = null,
        ?Closure $diskUrl = null,
        ?Closure $temporaryUrl = null,
    ): string {
        self::assertAvailable($media);

        if ($media->is_public) {
            return self::publicUrl($media, $parameters, $exists, $diskUrl);
        }

        return self::privateUrl($media, $parameters, $expiration, $owner, $exists, $diskUrl, $temporaryUrl);
    }

    /**
     * Build a centralized public asset URL.
     *
     * @param  array<string, scalar|null>  $parameters
     * @param  Closure(string, string): bool|null  $exists
     * @param  Closure(string, string): string|null  $diskUrl
     */
    public static function publicUrl(
        Media $media,
        array $parameters = [],
        ?Closure $exists = null,
        ?Closure $diskUrl = null,
    ): string {
        self::assertAvailable($media);

        $diskConfig = self::diskConfig($media->disk);
        $driver = $diskConfig['driver'] ?? 'local';
        $normalized = self::normalizeAssetParameters($parameters);
        $normalized = self::withoutUnavailableVariation($media, $normalized);

        if ($driver !== 'local' && self::remotePublicDelivery() === 'disk') {
            return self::diskUrl($media, (string) ($normalized['v'] ?? ''), $exists, $diskUrl);
        }

        if ($driver === 'local' && ! empty($diskConfig['url']) && $normalized === []) {
            return self::objectUrl($media->disk, self::mediaPath($media), $diskUrl);
        }

        return self::assetRouteUrl($media, array_merge(
            $normalized,
            ['version' => self::versionFor($media, (string) ($normalized['v'] ?? ''))],
        ));
    }

    /**
     * Build a centralized signed private asset URL.
     *
     * @param  array<string, scalar|null>  $parameters
     * @param  Closure(string, string): bool|null  $exists
     * @param  Closure(string, string): string|null  $diskUrl
     * @param  Closure(string, string, DateTimeInterface): string|null  $temporaryUrl
     */
    public static function privateUrl(
        Media $media,
        array $parameters = [],
        ?DateTimeInterface $expiration = null,
        ?string $owner = null,
        ?Closure $exists = null,
        ?Closure $diskUrl = null,
        ?Closure $temporaryUrl = null,
    ): string {
        self::assertAvailable($media);

        $defaultOwner = MediaConfiguration::string(
            'media.assets.private_owner_fallback',
            'system',
        );
        $resolvedOwner = $owner ?? $media->uploaded_by ?? $defaultOwner;
        $normalized = self::normalizeAssetParameters($parameters);
        $normalized = self::withoutUnavailableVariation($media, $normalized);
        $payload = array_merge(
            ['owner' => (string) $resolvedOwner, 'media' => $media->id],
            $normalized,
        );
        $routeName = MediaConfiguration::string(
            'media.assets.private_route_name',
            'media.private.show',
        );
        $fallbackLifetime = MediaConfiguration::integer('media.temporary_url_lifetime', 5, 1);
        $ttlMinutes = MediaConfiguration::integer(
            'media.assets.signed_url_lifetime',
            $fallbackLifetime,
            1,
        );
        $expiresAt = $expiration ?? now()->addMinutes(max(1, $ttlMinutes));

        try {
            return URL::temporarySignedRoute($routeName, $expiresAt, $payload);
        } catch (Throwable $routeException) {
            $path = self::pathForVariationOrOriginal($media, (string) ($normalized['v'] ?? ''));

            try {
                return self::objectTemporaryUrl($media->disk, $path, $expiresAt, $temporaryUrl);
            } catch (Throwable) {
                throw new RuntimeException(
                    "Private media [{$media->id}] cannot be delivered securely: no signed route or temporary disk URL is available.",
                    previous: $routeException,
                );
            }
        }
    }

    /**
     * Generate a temporary URL for a media object or named variation.
     *
     * @param  Closure(string, string): string|null  $diskUrl
     * @param  Closure(string, string, DateTimeInterface): string|null  $temporaryUrl
     */
    public static function temporaryUrl(
        Media $media,
        DateTimeInterface $expiration,
        string $variation = '',
        ?Closure $diskUrl = null,
        ?Closure $temporaryUrl = null,
    ): string {
        self::assertAvailable($media);

        if (! $media->is_public) {
            $parameters = $variation !== '' ? ['v' => $variation] : [];

            return self::privateUrl(
                $media,
                $parameters,
                $expiration,
                null,
                null,
                $diskUrl,
                $temporaryUrl,
            );
        }

        $path = self::pathForVariationOrOriginal($media, $variation);

        try {
            return self::objectTemporaryUrl($media->disk, $path, $expiration, $temporaryUrl);
        } catch (Throwable) {
            return self::objectUrl($media->disk, $path, $diskUrl);
        }
    }

    /**
     * Resolve the local filesystem path for a media object or named variation.
     *
     * @param  Closure(string, string): string|null  $localPath
     */
    public static function path(Media $media, string $variation = '', ?Closure $localPath = null): string
    {
        self::assertAvailable($media);

        return self::objectLocalPath(
            $media->disk,
            self::pathForVariationOrOriginal($media, $variation),
            $localPath,
        );
    }

    /**
     * Check if the original media object exists.
     *
     * @param  Closure(string, string): bool|null  $exists
     */
    public static function fileExists(Media $media, ?Closure $exists = null): bool
    {
        return self::objectExists($media->disk, self::mediaPath($media), $exists);
    }

    /**
     * @param  Closure(string, string): bool|null  $exists
     * @param  Closure(string, string): string|null  $diskUrl
     */
    private static function forVariationLabel(
        Media $media,
        string $label,
        ?Closure $exists,
        ?Closure $diskUrl,
        ?Closure $temporaryUrl,
    ): string {
        $variation = self::availableVariation($media, $label);

        if ($variation === null) {
            return self::forMedia($media, '', $exists, $diskUrl, $temporaryUrl);
        }

        return self::forVariation($variation, $exists, $diskUrl, $temporaryUrl);
    }

    /**
     * @param  array<string, scalar>  $queryParams
     */
    private static function assetRouteUrl(Media $media, array $queryParams = []): string
    {
        $payload = array_merge(['media' => $media->id], $queryParams);
        $routeName = MediaConfiguration::string(
            'media.assets.public_route_name',
            'media.assets.show',
        );

        try {
            return route($routeName, $payload);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  Closure(string, string): bool|null  $exists
     * @param  Closure(string, string): string|null  $diskUrl
     */
    private static function diskUrl(
        Media $media,
        string $variation = '',
        ?Closure $exists = null,
        ?Closure $diskUrl = null,
    ): string {
        if ($variation !== '') {
            $var = self::availableVariation($media, $variation);

            if ($var !== null && self::objectExists($media->disk, $var->getPath(), $exists)) {
                return self::appendQuery(
                    self::objectUrl($media->disk, $var->getPath(), $diskUrl),
                    ['version' => self::versionFor($media, $variation)],
                );
            }
        }

        return self::objectUrl($media->disk, self::mediaPath($media), $diskUrl);
    }

    private static function pathForVariationOrOriginal(Media $media, string $variation): string
    {
        if ($variation !== '') {
            $var = self::availableVariation($media, $variation);

            if ($var !== null) {
                return $var->getPath();
            }
        }

        return self::mediaPath($media);
    }

    private static function mediaPath(Media $media): string
    {
        return $media->buildPath();
    }

    /**
     * @param  Closure(string, string): bool|null  $exists
     */
    private static function objectExists(string $disk, string $path, ?Closure $exists): bool
    {
        if ($exists instanceof Closure) {
            return (bool) $exists($disk, $path);
        }

        return Storage::disk($disk)->exists($path);
    }

    /**
     * @param  Closure(string, string): string|null  $diskUrl
     */
    private static function objectUrl(string $disk, string $path, ?Closure $diskUrl): string
    {
        if ($diskUrl instanceof Closure) {
            return (string) $diskUrl($disk, $path);
        }

        return Storage::disk($disk)->url($path);
    }

    /**
     * @param  Closure(string, string, DateTimeInterface): string|null  $temporaryUrl
     */
    private static function objectTemporaryUrl(
        string $disk,
        string $path,
        DateTimeInterface $expiration,
        ?Closure $temporaryUrl,
    ): string {
        if ($temporaryUrl instanceof Closure) {
            return (string) $temporaryUrl($disk, $path, $expiration);
        }

        return Storage::disk($disk)->temporaryUrl($path, $expiration);
    }

    /**
     * @param  Closure(string, string): string|null  $localPath
     */
    private static function objectLocalPath(string $disk, string $path, ?Closure $localPath): string
    {
        if ($localPath instanceof Closure) {
            return (string) $localPath($disk, $path);
        }

        if ((self::diskConfig($disk)['driver'] ?? 'local') !== 'local') {
            throw new RuntimeException("Disk [{$disk}] does not support local path resolution.");
        }

        return Storage::disk($disk)->path($path);
    }

    /**
     * @return array<string, mixed>
     */
    private static function diskConfig(string $disk): array
    {
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config)) {
            return [];
        }

        $normalized = [];

        foreach ($config as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private static function remotePublicDelivery(): string
    {
        $mode = config('media.assets.remote_public_delivery', 'route');

        return $mode === 'disk' ? 'disk' : 'route';
    }

    /**
     * Resolve the configured subset of query parameters supported by asset delivery.
     *
     * @return list<string>
     */
    public static function allowedParameters(): array
    {
        $configured = MediaConfiguration::stringList(
            'media.assets.allowed_parameters',
            self::SUPPORTED_ASSET_PARAMETERS,
        );

        return array_values(array_intersect(
            self::SUPPORTED_ASSET_PARAMETERS,
            $configured,
        ));
    }

    /**
     * Build the public cache version for an original or named variation.
     */
    private static function versionFor(Media $media, string $variation = ''): string
    {
        if ($variation === '') {
            return MediaAssetVersion::short($media);
        }

        $record = self::availableVariation($media, $variation);

        if (! $record instanceof MediaImageVariation) {
            return MediaAssetVersion::short($media);
        }

        return MediaAssetVersion::short($media, $record);
    }

    /**
     * Resolve a named variation only when it belongs to the current usable source.
     */
    private static function availableVariation(Media $media, string $label): ?MediaImageVariation
    {
        $variation = $media->getVariation($label);

        if (! $variation instanceof MediaImageVariation
            || ! self::isCurrentAvailableVariation($media, $variation)) {
            return null;
        }

        return $variation;
    }

    /**
     * Determine whether a variation is deliverable for the media's current revision.
     */
    private static function isCurrentAvailableVariation(
        Media $media,
        MediaImageVariation $variation,
    ): bool {
        return $variation->status === MediaLifecycleStatus::Available->value
            && $variation->source_revision === $media->revision;
    }

    /**
     * Remove named variations that cannot be delivered, preserving original fallback behavior.
     *
     * @param  array<string, scalar>  $parameters
     * @return array<string, scalar>
     */
    private static function withoutUnavailableVariation(Media $media, array $parameters): array
    {
        $label = $parameters['v'] ?? null;

        if ($label === null
            || self::availableVariation($media, (string) $label) instanceof MediaImageVariation) {
            return $parameters;
        }

        unset($parameters['v']);

        return $parameters;
    }

    /**
     * Reject URL and path resolution for media outside its usable lifecycle.
     */
    private static function assertAvailable(Media $media): void
    {
        if (! $media->isAvailable()) {
            throw new RuntimeException("Media [{$media->id}] is not available for delivery.");
        }
    }

    /**
     * Append encoded query parameters without discarding an existing query string.
     *
     * @param  array<string, scalar>  $parameters
     */
    private static function appendQuery(string $url, array $parameters): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, scalar|null>  $parameters
     * @return array<string, scalar>
     */
    private static function normalizeAssetParameters(array $parameters): array
    {
        $normalized = [];

        foreach (self::allowedParameters() as $parameterKey) {
            if (! array_key_exists($parameterKey, $parameters)) {
                continue;
            }

            $value = $parameters[$parameterKey] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $normalized[$parameterKey] = $value;
        }

        return $normalized;
    }
}
