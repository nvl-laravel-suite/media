<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use DateTimeImmutable;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\RecoverableMultipartUploadGateway;
use Nvl\Media\Data\Multipart\CompletedMultipartObjectData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\MediaMultipartUpload;
use Throwable;

/**
 * First-party AWS S3 and S3-compatible multipart gateway.
 */
final readonly class S3MultipartUploadGateway implements RecoverableMultipartUploadGateway
{
    public function __construct(
        private MediaDiskGateway $disks,
    ) {}

    public function initiate(MultipartUploadSessionData $session): void
    {
        [$client, $bucket, $key] = $this->provider($session);
        $result = $client->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'ContentType' => $session->mimeType,
            'ChecksumAlgorithm' => 'SHA256',
        ]);
        $providerUploadId = $result->get('UploadId');

        if (! is_string($providerUploadId) || $providerUploadId === '') {
            throw new MediaUploadException('S3 did not return a multipart upload identifier.');
        }

        try {
            MediaMultipartUpload::query()
                ->whereKey($session->uploadId)
                ->firstOrFail()
                ->forceFill([
                    'provider_state' => [
                        'upload_id' => $providerUploadId,
                        'bucket' => $bucket,
                        'key' => $key,
                    ],
                ])->save();
        } catch (Throwable $exception) {
            try {
                $this->abortProvider($client, $bucket, $key, $providerUploadId);
            } catch (Throwable $cleanupException) {
                Log::error('S3 multipart initiation compensation failed.', [
                    'session_id' => $session->uploadId,
                    'disk' => $session->disk,
                    'exception' => $cleanupException::class,
                    'error' => $cleanupException->getMessage(),
                ]);
            }

            throw $exception;
        }
    }

    public function signPart(
        MultipartUploadSessionData $session,
        SignMultipartPartData $part,
    ): SignedMultipartPartData {
        [$client, $bucket, $key, $providerUploadId] = $this->activeProvider($session);
        $checksum = $this->base64Checksum($part->checksum);
        $command = $client->getCommand('UploadPart', [
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $providerUploadId,
            'PartNumber' => $part->partNumber,
            'ContentLength' => $part->byteLength,
            'ChecksumSHA256' => $checksum,
        ]);
        $expiresAt = new DateTimeImmutable('+15 minutes');
        $request = $client->createPresignedRequest($command, $expiresAt);
        $headers = $this->flattenHeaders($request->getHeaders());

        $headers['content-length'] = (string) $part->byteLength;
        $headers['x-amz-checksum-sha256'] = $checksum;

        return new SignedMultipartPartData(
            partNumber: $part->partNumber,
            url: (string) $request->getUri(),
            headers: $headers,
            expiresAt: $expiresAt,
        );
    }

    public function complete(
        MultipartUploadSessionData $session,
        CompleteMultipartUploadData $completion,
    ): CompletedMultipartObjectData {
        [$client, $bucket, $key, $providerUploadId] = $this->activeProvider($session);
        $persisted = MediaMultipartUpload::query()->findOrFail($session->uploadId);
        $signedParts = is_array($persisted->signed_parts) ? $persisted->signed_parts : [];
        $parts = [];

        foreach ($completion->parts as $part) {
            $signed = $signedParts[$part->partNumber] ?? null;

            if ($signed === null) {
                throw new MediaUploadException(
                    "S3 multipart part [{$part->partNumber}] has no persisted checksum.",
                );
            }

            $parts[] = [
                'ETag' => $part->etag,
                'PartNumber' => $part->partNumber,
                'ChecksumSHA256' => $this->base64Checksum((string) $signed['checksum']),
            ];
        }

        $result = $client->completeMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $providerUploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        return $this->inspectObject(
            $session,
            $client,
            $bucket,
            $key,
            $this->identity($result->get('ETag'), $result->get('VersionId')),
        );
    }

    public function inspect(
        MultipartUploadSessionData $session,
    ): ?CompletedMultipartObjectData {
        [$client, $bucket, $key] = $this->provider($session);

        try {
            return $this->inspectObject($session, $client, $bucket, $key);
        } catch (Throwable) {
            return null;
        }
    }

    public function abort(MultipartUploadSessionData $session): void
    {
        [$client, $bucket, $key] = $this->provider($session);
        $persisted = MediaMultipartUpload::query()->findOrFail($session->uploadId);
        $providerUploadId = $this->providerUploadId(
            $session,
            $persisted->provider_state,
            $bucket,
            $key,
            required: false,
        );

        if ($providerUploadId === null) {
            $this->abortMatchingProviderUploads($client, $bucket, $key);

            return;
        }

        $this->abortProvider($client, $bucket, $key, $providerUploadId);
    }

    /**
     * @return array{S3Client, string, string}
     */
    private function provider(MultipartUploadSessionData $session): array
    {
        $filesystem = Storage::disk($session->disk);

        if (! $filesystem instanceof AwsS3V3Adapter) {
            throw new MediaUploadException(
                "Multipart disk [{$session->disk}] is not an S3-compatible Laravel disk.",
            );
        }

        $configuration = $filesystem->getConfig();
        $bucket = $configuration['bucket'] ?? null;

        if (! is_string($bucket) || $bucket === '') {
            throw new MediaUploadException(
                "Multipart disk [{$session->disk}] does not define an S3 bucket.",
            );
        }

        $root = $configuration['root'] ?? '';
        $key = implode('/', array_filter([
            is_string($root) ? trim($root, '/') : '',
            ltrim($session->objectKey, '/'),
        ]));

        return [$filesystem->getClient(), $bucket, $key];
    }

    /**
     * @return array{S3Client, string, string, string}
     */
    private function activeProvider(MultipartUploadSessionData $session): array
    {
        [$client, $bucket, $key] = $this->provider($session);
        $persisted = MediaMultipartUpload::query()->findOrFail($session->uploadId);
        $providerUploadId = $this->providerUploadId(
            $session,
            $persisted->provider_state,
            $bucket,
            $key,
        );

        if ($providerUploadId === null) {
            throw new MediaUploadException(
                "Multipart session [{$session->uploadId}] has no recoverable provider state.",
            );
        }

        return [$client, $bucket, $key, $providerUploadId];
    }

    private function inspectObject(
        MultipartUploadSessionData $session,
        S3Client $client,
        string $bucket,
        string $key,
        ?string $identity = null,
    ): CompletedMultipartObjectData {
        $head = $client->headObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'ChecksumMode' => 'ENABLED',
        ]);
        $size = $head->get('ContentLength');
        $mimeType = $head->get('ContentType');
        $providerChecksum = $head->get('ChecksumSHA256');
        $checksum = $this->fullObjectChecksum(
            $session,
            $providerChecksum,
            $head->get('ChecksumType'),
        );

        if ((! is_int($size) && ! is_numeric($size))
            || ! is_string($mimeType)
            || $mimeType === ''
            || $checksum === '') {
            throw new MediaUploadException('S3 returned incomplete metadata for a completed multipart object.');
        }

        return new CompletedMultipartObjectData(
            path: $session->objectKey,
            checksum: mb_strtolower($checksum),
            size: (int) $size,
            mimeType: $mimeType,
            objectIdentity: $identity ?? $this->identity($head->get('ETag'), $head->get('VersionId')),
        );
    }

    private function fullObjectChecksum(
        MultipartUploadSessionData $session,
        mixed $providerChecksum,
        mixed $checksumType,
    ): string {
        if ($checksumType === 'FULL_OBJECT'
            && is_string($providerChecksum)
            && $providerChecksum !== '') {
            $binary = base64_decode($providerChecksum, true);

            if (is_string($binary) && strlen($binary) === 32) {
                return bin2hex($binary);
            }
        }

        return $this->disks->checksum($session->disk, $session->objectKey);
    }

    /**
     * Resolve and validate the persisted provider upload identifier.
     *
     * @param  array<string, mixed>|null  $providerState
     */
    private function providerUploadId(
        MultipartUploadSessionData $session,
        ?array $providerState,
        string $bucket,
        string $key,
        bool $required = true,
    ): ?string {
        if ($providerState === null) {
            if (! $required) {
                return null;
            }

            throw new MediaUploadException(
                "Multipart session [{$session->uploadId}] has no recoverable provider state.",
            );
        }

        $providerUploadId = $providerState['upload_id'] ?? null;
        $persistedBucket = $providerState['bucket'] ?? null;
        $persistedKey = $providerState['key'] ?? null;

        if (! is_string($persistedBucket)
            || ! hash_equals($bucket, $persistedBucket)
            || ! is_string($persistedKey)
            || ! hash_equals($key, $persistedKey)) {
            throw new MediaUploadException(
                "Multipart session [{$session->uploadId}] provider identity does not match its disk.",
            );
        }

        if (! is_string($providerUploadId) || $providerUploadId === '') {
            if (! $required) {
                return null;
            }

            throw new MediaUploadException(
                "Multipart session [{$session->uploadId}] has no recoverable provider state.",
            );
        }

        return $providerUploadId;
    }

    private function abortMatchingProviderUploads(
        S3Client $client,
        string $bucket,
        string $key,
    ): void {
        $pages = $client->getPaginator('ListMultipartUploads', [
            'Bucket' => $bucket,
            'Prefix' => $key,
        ]);

        foreach ($pages as $page) {
            $uploads = $page->get('Uploads');

            if (! is_array($uploads)) {
                continue;
            }

            foreach ($uploads as $upload) {
                if (! is_array($upload)) {
                    continue;
                }

                $candidateKey = $upload['Key'] ?? null;
                $candidateUploadId = $upload['UploadId'] ?? null;

                if (! is_string($candidateKey)
                    || ! hash_equals($key, $candidateKey)
                    || ! is_string($candidateUploadId)
                    || $candidateUploadId === '') {
                    continue;
                }

                $this->abortProvider($client, $bucket, $key, $candidateUploadId);
            }
        }
    }

    private function abortProvider(
        S3Client $client,
        string $bucket,
        string $key,
        string $providerUploadId,
    ): void {
        try {
            $client->abortMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $providerUploadId,
            ]);
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() === 'NoSuchUpload') {
                return;
            }

            throw $exception;
        }
    }

    private function base64Checksum(string $hexChecksum): string
    {
        $binary = hex2bin($hexChecksum);

        if ($binary === false) {
            throw new MediaUploadException('Multipart checksum is not valid hexadecimal SHA-256.');
        }

        return base64_encode($binary);
    }

    /**
     * @param  array<array-key, array<array-key, string>>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flattened = [];

        foreach ($headers as $name => $values) {
            if (! is_string($name)) {
                throw new MediaUploadException(
                    'S3 returned an invalid numeric presigned-request header.',
                );
            }

            $flattened[$name] = implode(', ', $values);
        }

        return $flattened;
    }

    private function identity(mixed $etag, mixed $versionId): ?string
    {
        $values = array_filter([
            is_string($etag) ? trim($etag, '"') : null,
            is_string($versionId) ? $versionId : null,
        ]);

        return $values === [] ? null : implode(':', $values);
    }
}
