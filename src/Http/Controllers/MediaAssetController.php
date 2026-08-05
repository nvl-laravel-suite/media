<?php

declare(strict_types=1);

namespace Nvl\Media\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAccessService;
use Nvl\Media\Services\MediaAssetService;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Support\MediaAssetUrl;
use Nvl\Media\Support\MediaAssetVersion;
use Nvl\Media\Support\MediaConfiguration;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** MediaAssetController serves centralized public/private media assets with ETag caching. */
final class MediaAssetController extends Controller
{
    public function __construct(
        private readonly MediaAssetService $assets,
        private readonly MediaDiskGateway $disks,
        private readonly MediaAuthorization $authorization,
        private readonly MediaAccessService $access,
    ) {}

    /**
     * Serve a public media asset from a central URL.
     */
    public function showPublic(Request $request, Media $media): BinaryFileResponse|StreamedResponse|Response
    {
        abort_unless($media->is_public && $media->isAvailable(), 404);

        $validated = $this->validatedQuery($request);
        try {
            $resolved = $this->assets->resolve(
                media: $media,
                variationLabel: $validated['v'] ?? null,
            );
        } catch (RuntimeException) {
            abort(404);
        }

        $this->ensureAssetVersionMatches($validated['version'], $resolved['etag']);

        return $this->serveFile(
            disk: $resolved['disk'],
            path: $resolved['path'],
            mimeType: $resolved['mime_type'],
            cacheControl: $this->publicCacheControl($validated['version']),
            request: $request,
            etag: $resolved['etag'],
            filename: $media->filename,
        );
    }

    /**
     * Serve a private media asset from a signed URL.
     */
    public function showPrivate(Request $request, string $owner, Media $media): BinaryFileResponse|StreamedResponse|Response
    {
        abort_if($media->is_public || ! $media->isAvailable(), 404);

        if (! $this->ownerMatches($owner, $media)) {
            abort(404);
        }

        $this->authorizePrivateDelivery($request, $owner, $media);

        $validated = $this->validatedQuery($request, signed: true);
        try {
            $resolved = $this->assets->resolve(
                media: $media,
                variationLabel: $validated['v'] ?? null,
            );
        } catch (RuntimeException) {
            abort(404);
        }

        $this->ensureAssetVersionMatches($validated['version'], $resolved['etag']);

        return $this->serveFile(
            disk: $resolved['disk'],
            path: $resolved['path'],
            mimeType: $resolved['mime_type'],
            cacheControl: MediaConfiguration::string(
                'media.assets.private_cache_control',
                'private, max-age=0, no-store',
            ),
            request: $request,
            etag: $resolved['etag'],
            filename: $media->filename,
        );
    }

    /**
     * Validate and normalize supported asset query params.
     *
     * @return array{v: string|null, version: string|null}
     */
    private function validatedQuery(Request $request, bool $signed = false): array
    {
        $maxLabelLength = 30;
        $allowedParameters = MediaAssetUrl::allowedParameters();
        $allowedQueryKeys = [
            ...$allowedParameters,
            'version',
            ...($signed ? ['expires', 'signature'] : []),
        ];
        $unknownQueryKeys = array_values(array_diff(
            array_keys($request->query()),
            $allowedQueryKeys,
        ));

        if ($unknownQueryKeys !== []) {
            abort(422, sprintf(
                'Unsupported media asset query parameter [%s].',
                (string) $unknownQueryKeys[0],
            ));
        }

        $rules = [
            'version' => ['nullable', 'string', 'size:16', 'regex:/^[a-f0-9]+$/'],
        ];

        if (in_array('v', $allowedParameters, true)) {
            $rules['v'] = ['nullable', 'string', "max:{$maxLabelLength}", 'regex:/^[a-zA-Z0-9_-]+$/'];
        }

        $validator = validator($request->query(), $rules);

        if ($validator->fails()) {
            abort(422, (string) $validator->errors()->first());
        }

        $variation = $request->query('v');
        $version = $request->query('version');

        return [
            'v' => is_string($variation) ? $variation : null,
            'version' => is_string($version) ? $version : null,
        ];
    }

    /**
     * Reject stale or fabricated immutable-cache identities.
     */
    private function ensureAssetVersionMatches(?string $requestedVersion, string $etag): void
    {
        if ($requestedVersion === null) {
            return;
        }

        abort_unless(
            hash_equals(MediaAssetVersion::shortFromEtag($etag), $requestedVersion),
            404,
        );
    }

    /**
     * Reserve long-lived immutable caching for authoritative versioned URLs.
     */
    private function publicCacheControl(?string $requestedVersion): string
    {
        if ($requestedVersion === null) {
            return 'public, max-age=0, must-revalidate';
        }

        return MediaConfiguration::string(
            'media.assets.public_cache_control',
            'public, max-age=31536000, immutable',
        );
    }

    private function ownerMatches(string $owner, Media $media): bool
    {
        $fallbackOwner = MediaConfiguration::string(
            'media.assets.private_owner_fallback',
            'system',
        );
        $expectedOwner = $media->uploaded_by ?? $fallbackOwner;

        return hash_equals((string) $expectedOwner, $owner);
    }

    /**
     * Enforce the consumer authorization contract for every private delivery.
     */
    private function authorizePrivateDelivery(Request $request, string $owner, Media $media): void
    {
        /** @var Authenticatable|null $user */
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            abort_unless(
                $this->access->allows($user, MediaAbility::Download, $media),
                403,
            );

            return;
        }

        abort_unless(
            $this->authorization->allows(
                MediaActorData::signed($owner),
                MediaAbility::Download,
                $media,
            ),
            403,
        );
    }

    private function serveFile(
        string $disk,
        string $path,
        string $mimeType,
        string $cacheControl,
        Request $request,
        string $etag = '',
        string $filename = '',
    ): BinaryFileResponse|StreamedResponse|Response {
        if ($etag !== '' && $this->matchesEtag($request, $etag)) {
            return response('', 304)->withHeaders([
                'ETag' => "\"{$etag}\"",
                'Cache-Control' => $cacheControl,
            ]);
        }

        $etagHeaders = $etag !== '' ? ['ETag' => "\"{$etag}\""] : [];
        $baseHeaders = array_merge([
            'Content-Type' => $mimeType,
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => $this->contentDisposition($filename),
        ], $etagHeaders);

        if ($this->disks->isLocal($disk)) {
            $absolutePath = $this->disks->localPath($disk, $path);

            abort_unless(file_exists($absolutePath), 404);

            return response()->file($absolutePath, $baseHeaders);
        }

        try {
            $contentLength = $this->disks->size($disk, $path);
        } catch (Throwable) {
            $contentLength = null;
        }

        $range = $this->resolveRange($request, $contentLength);
        $status = $range === null ? 200 : 206;
        $headers = $baseHeaders;

        if ($contentLength !== null) {
            $headers['Content-Length'] = (string) ($range === null
                ? $contentLength
                : $range['end'] - $range['start'] + 1);
        }

        if ($range !== null && $contentLength !== null) {
            $headers['Content-Range'] = "bytes {$range['start']}-{$range['end']}/{$contentLength}";
        }

        if ($request->isMethod('HEAD')) {
            return response('', $status, $headers);
        }

        $stream = $this->disks->readStream($disk, $path);

        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream, $range): void {
            $remaining = $range === null ? null : $range['end'] - $range['start'] + 1;

            if ($range !== null && $range['start'] > 0) {
                if (@fseek($stream, $range['start']) !== 0) {
                    $discard = $range['start'];

                    while ($discard > 0 && ! feof($stream)) {
                        $chunk = fread($stream, min(8192, $discard));

                        if ($chunk === false || $chunk === '') {
                            break;
                        }

                        $discard -= strlen($chunk);
                    }
                }
            }

            while (! feof($stream) && ($remaining === null || $remaining > 0)) {
                $chunk = fread($stream, $remaining === null ? 8192 : min(8192, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                flush();

                if ($remaining !== null) {
                    $remaining -= strlen($chunk);
                }
            }

            fclose($stream);
        }, $status, $headers);
    }

    /**
     * Parse one RFC 7233 byte range, rejecting malformed or multiple ranges.
     *
     * @return array{start: int, end: int}|null
     */
    private function resolveRange(Request $request, ?int $size): ?array
    {
        $header = $request->header('Range');

        if (! is_string($header) || trim($header) === '' || $size === null) {
            return null;
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches) !== 1) {
            abort(416, headers: ['Content-Range' => "bytes */{$size}"]);
        }

        if ($matches[1] === '' && $matches[2] === '') {
            abort(416, headers: ['Content-Range' => "bytes */{$size}"]);
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];

            if ($suffixLength < 1) {
                abort(416, headers: ['Content-Range' => "bytes */{$size}"]);
            }

            return [
                'start' => max(0, $size - $suffixLength),
                'end' => $size - 1,
            ];
        }

        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);

        if ($start >= $size || $end < $start) {
            abort(416, headers: ['Content-Range' => "bytes */{$size}"]);
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Build a safe inline content-disposition header.
     */
    private function contentDisposition(string $filename): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'media';

        return "inline; filename=\"{$fallback}\"; filename*=UTF-8''".rawurlencode($filename);
    }

    /**
     * Determine whether an If-None-Match header accepts the current entity tag.
     */
    private function matchesEtag(Request $request, string $etag): bool
    {
        $header = $request->header('If-None-Match');

        if (! is_string($header) || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if (hash_equals("\"{$etag}\"", $candidate)) {
                return true;
            }
        }

        return false;
    }
}
