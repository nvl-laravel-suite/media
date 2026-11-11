<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Nvl\Media\Enums\MediaDeliveryMode;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Ownership\MediaOwnership;
use Nvl\Media\Services\MediaDeliveryPolicy;
use Nvl\Media\Services\MediaFileExistence;
use RuntimeException;
use Throwable;

/**
 * Canonical URL assembly shared by model reads and the authorized URL resolver.
 * Capability builders are internal; callers issue grants through MediaUrlResolver.
 *
 * @internal
 */
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
        ?MediaDeliveryMode $mode = null,
    ): string {
        self::assertAvailable($media);

        if (! $media->is_public) {
            $parameters = $variation !== '' ? ['v' => $variation] : [];

            return self::authenticatedUrl($media, $parameters, $mode);
        }

        if ($variation !== '') {
            return self::forVariationLabel($media, $variation, $exists, $diskUrl, $temporaryUrl, $mode);
        }

        return self::publicUrl($media, [], $exists, $diskUrl, $mode);
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
        ?MediaDeliveryMode $mode = null,
    ): string {
        MediaOwnership::assertVariationOwner($variation);
        /** @var Media $media */
        $media = $variation->media;
        self::assertAvailable($media);

        if (! self::isCurrentAvailableVariation($media, $variation)) {
            return self::forMedia($media, '', $exists, $diskUrl, $temporaryUrl, $mode);
        }

        $path = $variation->getPath();

        if (! self::objectExists($media->disk, $path, $exists)) {
            return self::forMedia($media, '', $exists, $diskUrl, $temporaryUrl, $mode);
        }

        return self::buildUrl($media, ['v' => $variation->label], null, null, $exists, $diskUrl, $temporaryUrl, $mode);
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
        ?MediaDeliveryMode $mode = null,
    ): string {
        self::assertAvailable($media);

        if ($expiration !== null || $owner !== null) {
            throw new RuntimeException('Issue bearer links through the actor-authorized MediaUrlResolver.');
        }

        if ($media->is_public) {
            return self::publicUrl($media, $parameters, $exists, $diskUrl, $mode);
        }

        return self::authenticatedUrl($media, $parameters, $mode);
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
        ?MediaDeliveryMode $mode = null,
    ): string {
        self::assertAvailable($media);

        if (! $media->is_public) {
            throw new RuntimeException('Private media cannot have an unsigned public URL.');
        }

        $selected = (new MediaDeliveryPolicy)->mode($media->disk, MediaVisibility::Public, $mode);
        $normalized = self::normalizeAssetParameters($parameters);
        $normalized = self::withoutUnavailableVariation($media, $normalized);

        if ($selected === MediaDeliveryMode::Direct) {
            return self::diskUrl($media, (string) ($normalized['v'] ?? ''), $exists, $diskUrl);
        }

        return self::assetRouteUrl($media, array_merge(
            $normalized,
            ['version' => self::versionFor($media, (string) ($normalized['v'] ?? ''))],
        ));
    }

    /**
     * Build a protected application URL without issuing an access capability.
     * The consumer route must authenticate and authorize every download.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    public static function authenticatedUrl(Media $media, array $parameters = [], ?MediaDeliveryMode $mode = null): string
    {
        self::assertAvailable($media);

        if ($media->is_public) {
            throw new RuntimeException('Authenticated private delivery requires private media.');
        }

        $selected = (new MediaDeliveryPolicy)->mode($media->disk, MediaVisibility::Private, $mode);

        if ($selected !== MediaDeliveryMode::Application) {
            throw new RuntimeException('Bearer delivery requires an explicit actor-authorized URL operation.');
        }

        $parameters = self::withoutUnavailableVariation($media, self::normalizeAssetParameters($parameters));
        $route = MediaConfiguration::string('nvl-media.assets.authenticated_route_name', 'nvl.media.authenticated.show');

        try {
            return route($route, ['media' => $media->id, ...$parameters]);
        } catch (Throwable $exception) {
            throw new RuntimeException('The authenticated media application route is unavailable.', previous: $exception);
        }
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
        array $parameters,
        DateTimeInterface $expiration,
        ?string $owner = null,
        ?Closure $exists = null,
        ?Closure $diskUrl = null,
        ?Closure $temporaryUrl = null,
    ): string {
        self::assertAvailable($media);
        if ($media->is_public) {
            throw new RuntimeException('Private signed delivery requires private media.');
        }
        $policy = new MediaDeliveryPolicy;
        $policy->mode($media->disk, MediaVisibility::Private, MediaDeliveryMode::SignedApplication);

        $defaultOwner = MediaConfiguration::string(
            'nvl-media.assets.private_owner_fallback',
            'system',
        );
        $resolvedOwner = $owner ?? $media->uploaded_by ?? $defaultOwner;
        self::assertCapabilityParameters($media, $parameters);
        $normalized = self::normalizeAssetParameters($parameters);
        $payload = [
            'owner' => (string) $resolvedOwner,
            'media' => $media->id,
            'revision' => $media->revision,
            ...(config('nvl-ownership.enabled') === true && MediaOwnership::active() ? ['scope' => MediaOwnership::privateCapabilityKey()] : []),
            ...$normalized,
        ];
        $routeName = MediaConfiguration::string(
            'nvl-media.assets.private_route_name',
            'nvl.media.private.show',
        );
        $policy->assertExpiration($media->disk, $expiration);

        try {
            return URL::temporarySignedRoute($routeName, $expiration, $payload);
        } catch (Throwable $routeException) {
            if (config('nvl-ownership.enabled') === true && MediaOwnership::active()) {
                throw new RuntimeException('Owned private Media requires its scoped signed application route.', previous: $routeException);
            }
            throw new RuntimeException('The signed media application route is unavailable; no storage fallback is permitted.', previous: $routeException);
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
        $policy = new MediaDeliveryPolicy;
        $policy->mode($media->disk, $media->visibility, MediaDeliveryMode::TemporaryStorage);
        $policy->assertExpiration($media->disk, $expiration);
        if (! $media->is_public && MediaOwnership::active()) {
            throw new RuntimeException('Owned private Media requires its scoped signed application route.');
        }
        self::assertRequestedVariation($media, $variation);
        $path = self::pathForVariationOrOriginal($media, $variation);

        return self::objectTemporaryUrl($media->disk, $path, $expiration, $temporaryUrl);
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
        ?MediaDeliveryMode $mode = null,
    ): string {
        $variation = self::availableVariation($media, $label);

        if ($variation === null) {
            return self::forMedia($media, '', $exists, $diskUrl, $temporaryUrl, $mode);
        }

        return self::forVariation($variation, $exists, $diskUrl, $temporaryUrl, $mode);
    }

    /**
     * @param  array<string, scalar>  $queryParams
     */
    private static function assetRouteUrl(Media $media, array $queryParams = []): string
    {
        $payload = array_merge(['media' => $media->id], $queryParams);
        $routeName = MediaConfiguration::string(
            'nvl-media.assets.public_route_name',
            'nvl.media.assets.show',
        );

        try {
            return route($routeName, $payload);
        } catch (Throwable $exception) {
            throw new RuntimeException('The public media application route is unavailable.', previous: $exception);
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

        // The model helpers reach this without a resolver; they share its cached existence lookup
        // so rendering a variation URL does not ask the disk on every call.
        return app(MediaFileExistence::class)->exists($disk, $path);
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

    /**
     * Resolve the configured subset of query parameters supported by asset delivery.
     *
     * @return list<string>
     */
    public static function allowedParameters(): array
    {
        $configured = MediaConfiguration::stringList(
            'nvl-media.assets.allowed_parameters',
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
            || $variation->media_id !== $media->id
            || ! self::isCurrentAvailableVariation($media, $variation)) {
            return null;
        }

        MediaOwnership::assertVariationOwner($variation);
        MediaOwnership::assertVariation($variation, $media);

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
        MediaOwnership::assertAsset($media);
        if (! $media->isAvailable()) {
            throw new RuntimeException("Media [{$media->id}] is not available for delivery.");
        }
    }

    /** Reject capability requests which would silently broaden a variation to its original. */
    private static function assertRequestedVariation(Media $media, string $label): void
    {
        if ($label !== '' && ! self::availableVariation($media, $label) instanceof MediaImageVariation) {
            throw new RuntimeException("Media variation [{$label}] is unavailable for this source revision.");
        }
    }

    /**
     * Validate the requested scope before parameter filtering can discard it.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    private static function assertCapabilityParameters(Media $media, array $parameters): void
    {
        $variation = $parameters['v'] ?? null;
        if ($variation === null || $variation === '') {
            return;
        }
        if (! is_string($variation) || ! in_array('v', self::allowedParameters(), true)) {
            throw new RuntimeException('The requested variation cannot be represented by this signed application route.');
        }
        self::assertRequestedVariation($media, $variation);
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
