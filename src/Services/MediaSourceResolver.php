<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use finfo;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nvl\Media\Contracts\MediaHostResolver;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Support\MediaDiskResolver;
use Nvl\Media\Support\MediaMimeResolver;
use Throwable;

/**
 * Normalizes remote, encoded, disk, string, and request sources into bounded uploads.
 */
final class MediaSourceResolver
{
    private const int STREAM_CHUNK_SIZE = 8192;

    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaHostResolver $hostResolver,
        private readonly MediaTemporaryFileRegistry $temporaryFiles,
    ) {}

    /**
     * Download a file from a DNS-pinned URL with redirect-safe SSRF protection.
     *
     * @throws MediaUploadException
     * @throws FileUnacceptableForCollection
     */
    public function fromUrl(string $url, string ...$allowedMimeTypes): UploadedFile
    {
        if (! (bool) config('media.sources.remote.enabled', false)) {
            $this->logRemoteRejection($url, new MediaUploadException('Remote media sources are disabled.'));

            throw new MediaUploadException('Remote media sources are disabled.');
        }

        $temporaryPath = $this->createTempFile();

        try {
            $finalUrl = $this->downloadUrlToTempFile($url, $temporaryPath);
            $filename = basename(parse_url($finalUrl, PHP_URL_PATH) ?: '') ?: 'downloaded-file';
            $mimeType = $this->detectMime($temporaryPath);
            $this->validateMimeType($mimeType, array_values($allowedMimeTypes));

            return new UploadedFile($temporaryPath, $filename, $mimeType, null, true);
        } catch (FileUnacceptableForCollection|MediaUploadException $exception) {
            $this->temporaryFiles->release($temporaryPath);
            $this->logRemoteRejection($url, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $this->temporaryFiles->release($temporaryPath);
            $this->logRemoteRejection($url, $exception);

            throw new MediaUploadException(
                "Could not download file from URL [{$url}]: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    /**
     * Stream strict base64 data into a bounded temporary upload.
     *
     * @throws MediaUploadException
     * @throws FileUnacceptableForCollection
     */
    public function fromBase64(string $data, string ...$allowedMimeTypes): UploadedFile
    {
        $estimatedBytes = $this->estimatedDecodedBytes($data);

        if ($estimatedBytes > $this->maxSourceBytes()) {
            throw new MediaUploadException('Decoded base64 media exceeds the maximum allowed size.');
        }

        $temporaryPath = $this->createTempFile();

        try {
            $this->decodeBase64ToFile($data, $temporaryPath);
            $mimeType = $this->detectMime($temporaryPath);
            $this->validateMimeType($mimeType, array_values($allowedMimeTypes));
            $extension = MediaMimeResolver::mimeToExtension($mimeType);
            $filename = 'media-'.bin2hex(random_bytes(12)).'.'.$extension;

            return new UploadedFile($temporaryPath, $filename, $mimeType, null, true);
        } catch (FileUnacceptableForCollection|MediaUploadException $exception) {
            $this->temporaryFiles->release($temporaryPath);

            throw $exception;
        } catch (Throwable $exception) {
            $this->temporaryFiles->release($temporaryPath);

            throw new MediaUploadException(
                "Failed to decode base64 media: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    /**
     * Create a bounded text upload.
     *
     * @throws MediaUploadException
     */
    public function fromString(string $text): UploadedFile
    {
        if (strlen($text) > $this->maxSourceBytes()) {
            throw new MediaUploadException('Text media exceeds the maximum allowed size.');
        }

        $temporaryPath = $this->createTempFile();

        try {
            $this->writeAll($temporaryPath, $text);
        } catch (Throwable $exception) {
            $this->temporaryFiles->release($temporaryPath);

            throw $exception;
        }

        return new UploadedFile($temporaryPath, 'text.txt', 'text/plain', null, true);
    }

    /**
     * Stream a storage object into a bounded temporary upload.
     *
     * @throws MediaUploadException
     */
    public function fromDisk(string $key, ?string $disk = null): UploadedFile
    {
        $resolvedDisk = MediaDiskResolver::resolve($disk);
        $input = $this->disks->readStream($resolvedDisk, $key);

        if (! is_resource($input)) {
            throw new MediaUploadException("File [{$key}] not found on disk [{$resolvedDisk}].");
        }

        $temporaryPath = $this->createTempFile();

        try {
            $this->copyBoundedStream($input, $temporaryPath, "disk object [{$key}]");
            $mimeType = $this->detectMime($temporaryPath);

            return new UploadedFile($temporaryPath, basename($key), $mimeType, null, true);
        } catch (Throwable $exception) {
            $this->temporaryFiles->release($temporaryPath);

            throw $exception;
        } finally {
            fclose($input);
        }
    }

    /**
     * Extract an application-owned request upload without claiming ownership.
     *
     * @throws MediaUploadException
     */
    public function fromRequest(Request $request, string $key): UploadedFile
    {
        $file = $request->file($key);

        if (! $file instanceof UploadedFile) {
            throw new MediaUploadException("No file found in request for key [{$key}].");
        }

        return $file;
    }

    /**
     * Download a URL while resolving, validating, and pinning each redirect hop.
     */
    private function downloadUrlToTempFile(string $url, string $temporaryPath): string
    {
        $currentUrl = $url;
        $maximumRedirects = MediaConfiguration::integer('media.sources.remote.redirects', 5, 0);

        for ($redirects = 0; $redirects <= $maximumRedirects; $redirects++) {
            $target = $this->validateUrl($currentUrl);
            $response = $this->requestPinned($currentUrl, $target);

            if ($this->isRedirect($response)) {
                if ($redirects === $maximumRedirects) {
                    $response->close();

                    throw new MediaUploadException('Too many redirects while downloading media URL.');
                }

                $nextUrl = $this->resolveRedirectUrl($currentUrl, $response->header('Location'));
                $response->close();
                $currentUrl = $nextUrl;

                continue;
            }

            if (! $response->successful()) {
                $status = $response->status();
                $response->close();

                throw new MediaUploadException(
                    "Could not download file from URL [{$currentUrl}]. HTTP {$status}.",
                );
            }

            $this->ensureContentLengthAllowed($response, $currentUrl);
            $this->writeResponseToTempFile($response, $temporaryPath, $currentUrl);

            return $currentUrl;
        }

        throw new MediaUploadException('Too many redirects while downloading media URL.');
    }

    /**
     * Perform a TLS-verified request pinned to one of the already validated IPs.
     *
     * @param  array{host: string, port: int, ips: list<string>}  $target
     */
    private function requestPinned(string $url, array $target): Response
    {
        $lastException = null;

        foreach ($target['ips'] as $ip) {
            $connectedIp = null;
            $curlResolve = $this->curlResolveEntry($target['host'], $target['port'], $ip);

            try {
                $response = Http::connectTimeout(
                    MediaConfiguration::integer('media.sources.remote.connect_timeout', 5, 1),
                )
                    ->timeout(MediaConfiguration::integer('media.sources.remote.total_timeout', 30, 1))
                    ->withOptions([
                        'allow_redirects' => false,
                        'stream' => true,
                        'verify' => true,
                        'curl' => [
                            CURLOPT_RESOLVE => [$curlResolve],
                            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        ],
                        'on_stats' => static function (TransferStats $stats) use (&$connectedIp): void {
                            $handlerStats = $stats->getHandlerStats();
                            $primaryIp = $handlerStats['primary_ip'] ?? null;

                            if (is_string($primaryIp) && $primaryIp !== '') {
                                $connectedIp = trim($primaryIp, '[]');
                            }
                        },
                    ])
                    ->get($url);
            } catch (ConnectionException $exception) {
                $lastException = $exception;

                continue;
            }

            $verifyConnectedIp = (bool) config(
                'media.sources.remote.verify_connected_ip',
                true,
            );

            if ($verifyConnectedIp && ! is_string($connectedIp)) {
                $response->close();

                throw new MediaUploadException(
                    'Remote media connection could not attest its connected IP.',
                );
            }

            if (is_string($connectedIp) && ! $this->sameIp($ip, $connectedIp)) {
                $response->close();

                throw new MediaUploadException(
                    "Remote media connection used unexpected IP [{$connectedIp}].",
                );
            }

            return $response;
        }

        throw new MediaUploadException(
            "Could not connect to validated remote media host [{$target['host']}].",
            previous: $lastException,
        );
    }

    /**
     * @return array{host: string, port: int, ips: list<string>}
     */
    private function validateUrl(string $url): array
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new MediaUploadException("Invalid URL: [{$url}].");
        }

        $scheme = strtolower((string) $parsed['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new MediaUploadException(
                "URL scheme [{$scheme}] is not allowed. Only http and https are permitted.",
            );
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new MediaUploadException('Credentials are not allowed in remote media URLs.');
        }

        $port = isset($parsed['port'])
            ? (int) $parsed['port']
            : ($scheme === 'https' ? 443 : 80);
        $allowedPorts = MediaConfiguration::integerList(
            'media.sources.remote.allowed_ports',
            [80, 443],
        );

        if (! in_array($port, $allowedPorts, true)) {
            throw new MediaUploadException("Remote media URL port [{$port}] is not allowed.");
        }

        $host = trim((string) $parsed['host'], '[]');
        $ips = $this->hostResolver->resolve($host);

        if ($ips === []) {
            throw new MediaUploadException("URL host [{$host}] could not be resolved.");
        }

        foreach ($ips as $ip) {
            if (filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                throw new MediaUploadException(
                    'URL resolves to a private or reserved IP address. Access denied.',
                );
            }
        }

        return [
            'host' => $host,
            'port' => $port,
            'ips' => array_values(array_unique($ips)),
        ];
    }

    private function curlResolveEntry(string $host, int $port, string $ip): string
    {
        $resolvedIp = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return "{$host}:{$port}:{$resolvedIp}";
    }

    private function sameIp(string $expected, string $actual): bool
    {
        $expectedBinary = @inet_pton(trim($expected, '[]'));
        $actualBinary = @inet_pton(trim($actual, '[]'));

        return is_string($expectedBinary)
            && is_string($actualBinary)
            && hash_equals($expectedBinary, $actualBinary);
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        $location = trim($location);

        if ($location === '') {
            throw new MediaUploadException('Redirect response did not include a Location header.');
        }

        return (string) UriResolver::resolve(
            Utils::uriFor($currentUrl),
            Utils::uriFor($location),
        );
    }

    private function ensureContentLengthAllowed(Response $response, string $url): void
    {
        $contentLength = trim($response->header('Content-Length'));

        if ($contentLength === '') {
            return;
        }

        $declaredBytes = filter_var($contentLength, FILTER_VALIDATE_INT);

        if (is_int($declaredBytes) && $declaredBytes > $this->maxRemoteBytes()) {
            $response->close();

            throw new MediaUploadException(
                "File from URL [{$url}] exceeds the maximum allowed size.",
            );
        }
    }

    private function writeResponseToTempFile(
        Response $response,
        string $temporaryPath,
        string $url,
    ): void {
        $input = $response->resource();

        try {
            $this->copyBoundedStream(
                $input,
                $temporaryPath,
                "file from URL [{$url}]",
                $this->maxRemoteBytes(),
            );
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            $response->close();
        }
    }

    /**
     * @param  resource  $input
     */
    private function copyBoundedStream(
        mixed $input,
        string $temporaryPath,
        string $source,
        ?int $maximumBytes = null,
    ): void {
        $output = fopen($temporaryPath, 'wb');

        if ($output === false) {
            throw new MediaUploadException('Failed to open temporary media file for writing.');
        }

        $bytesWritten = 0;
        $maximumBytes ??= $this->maxSourceBytes();

        try {
            while (! feof($input)) {
                $chunk = fread($input, self::STREAM_CHUNK_SIZE);

                if ($chunk === false) {
                    throw new MediaUploadException("Could not read {$source}.");
                }

                if ($chunk === '') {
                    continue;
                }

                $bytesWritten += strlen($chunk);

                if ($bytesWritten > $maximumBytes) {
                    throw new MediaUploadException(
                        ucfirst($source).' exceeds the maximum allowed size.',
                    );
                }

                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new MediaUploadException('Failed to write temporary media file.');
                }
            }
        } finally {
            fclose($output);
        }
    }

    private function estimatedDecodedBytes(string $data): int
    {
        $encodedCharacters = 0;
        $padding = 0;

        for ($offset = 0, $length = strlen($data); $offset < $length; $offset++) {
            $character = $data[$offset];

            if (str_contains(" \r\n\t", $character)) {
                continue;
            }

            if (! ctype_alnum($character) && ! in_array($character, ['+', '/', '='], true)) {
                throw new MediaUploadException('Invalid base64 data.');
            }

            $encodedCharacters++;

            if ($character === '=') {
                $padding++;
            }
        }

        if ($encodedCharacters % 4 !== 0 || $padding > 2) {
            throw new MediaUploadException('Invalid base64 data.');
        }

        return max(0, intdiv($encodedCharacters * 3, 4) - $padding);
    }

    private function decodeBase64ToFile(string $data, string $temporaryPath): void
    {
        $output = fopen($temporaryPath, 'wb');

        if ($output === false) {
            throw new MediaUploadException('Failed to open temporary media file for writing.');
        }

        $carry = '';
        $decodedBytes = 0;

        try {
            for ($offset = 0, $length = strlen($data); $offset < $length; $offset += self::STREAM_CHUNK_SIZE) {
                $encodedChunk = str_replace(
                    [' ', "\r", "\n", "\t"],
                    '',
                    substr($data, $offset, self::STREAM_CHUNK_SIZE),
                );
                $carry .= $encodedChunk;
                $processableLength = max(0, intdiv(max(0, strlen($carry) - 4), 4) * 4);

                if ($processableLength === 0) {
                    continue;
                }

                $this->writeDecodedChunk(
                    substr($carry, 0, $processableLength),
                    $output,
                    $decodedBytes,
                );
                $carry = substr($carry, $processableLength);
            }

            if ($carry !== '') {
                $this->writeDecodedChunk($carry, $output, $decodedBytes);
            }
        } finally {
            fclose($output);
        }
    }

    /**
     * @param  resource  $output
     */
    private function writeDecodedChunk(string $encoded, mixed $output, int &$decodedBytes): void
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw new MediaUploadException('Invalid base64 data.');
        }

        $decodedBytes += strlen($decoded);

        if ($decodedBytes > $this->maxSourceBytes()) {
            throw new MediaUploadException('Decoded base64 media exceeds the maximum allowed size.');
        }

        if (fwrite($output, $decoded) !== strlen($decoded)) {
            throw new MediaUploadException('Failed to write decoded media to temporary file.');
        }
    }

    private function createTempFile(): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'media_');

        if ($temporaryPath === false) {
            throw new MediaUploadException('Failed to create temporary media file.');
        }

        $this->temporaryFiles->track($temporaryPath);

        return $temporaryPath;
    }

    private function writeAll(string $path, string $contents): void
    {
        $written = file_put_contents($path, $contents, LOCK_EX);

        if ($written !== strlen($contents)) {
            throw new MediaUploadException('Failed to write temporary media file.');
        }
    }

    private function detectMime(string $path): string
    {
        return (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    }

    /**
     * @param  list<string>  $allowedMimeTypes
     */
    private function validateMimeType(string $mimeType, array $allowedMimeTypes): void
    {
        if ($allowedMimeTypes !== [] && ! in_array($mimeType, $allowedMimeTypes, true)) {
            throw new FileUnacceptableForCollection(
                "File MIME type [{$mimeType}] is not in allowed list.",
            );
        }
    }

    private function logRemoteRejection(string $url, Throwable $exception): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        Log::warning('Media remote source was rejected.', [
            'host' => is_string($host) ? $host : null,
            'scheme' => is_string($scheme) ? mb_strtolower($scheme) : null,
            'exception' => $exception::class,
            'error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }

    private function maxSourceBytes(): int
    {
        return MediaConfiguration::integer(
            'media.max_file_size',
            10 * 1024 * 1024,
            1,
        );
    }

    private function maxRemoteBytes(): int
    {
        return min(
            $this->maxSourceBytes(),
            MediaConfiguration::integer(
                'media.sources.remote.maximum_bytes',
                $this->maxSourceBytes(),
                1,
            ),
        );
    }
}
