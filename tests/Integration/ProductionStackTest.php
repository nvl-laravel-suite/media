<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Data\Multipart\CompletedMultipartPartData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\S3MultipartUploadGateway;

it('proves PostgreSQL, Redis locking, and S3-compatible multipart recovery together', function (): void {
    if (! filter_var(env('NVL_MEDIA_PRODUCTION_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Production-stack services are not enabled.');
    }

    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $redisStore = 'media-production-integration';
    config([
        "cache.stores.{$redisStore}" => [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
        ],
        'database.redis.client' => 'phpredis',
        'database.redis.options.prefix' => 'nvl-media-integration:',
        'database.redis.default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 0,
        ],
        'media.mutation_lock.store' => $redisStore,
        'media.mutation_lock.seconds' => 10,
        'media.mutation_lock.wait_seconds' => 0,
    ]);
    Cache::forgetDriver($redisStore);

    $mediaId = (string) Str::uuid();
    $lockKey = 'media:mutation:'.hash('sha256', $mediaId);
    $competingLock = Cache::store($redisStore)->lock($lockKey, 10);

    expect($competingLock->get())->toBeTrue();

    try {
        expect(fn (): mixed => app(MediaMutationLock::class)->execute(
            $mediaId,
            static fn (): bool => true,
        ))->toThrow(MediaUploadException::class, 'Timed out');
    } finally {
        $competingLock->release();
    }

    expect(app(MediaMutationLock::class)->execute(
        $mediaId,
        static fn (): string => 'acquired',
    ))->toBe('acquired');

    $disk = 'media-production-s3';
    $bucket = (string) env('MINIO_BUCKET', 'nvl-media');
    config([
        "filesystems.disks.{$disk}" => [
            'driver' => 's3',
            'key' => env('MINIO_ACCESS_KEY', 'minioadmin'),
            'secret' => env('MINIO_SECRET_KEY', 'minioadmin'),
            'region' => env('MINIO_REGION', 'us-east-1'),
            'bucket' => $bucket,
            'endpoint' => env('MINIO_ENDPOINT', 'http://127.0.0.1:9000'),
            'use_path_style_endpoint' => true,
            'throw' => true,
        ],
    ]);
    Storage::forgetDisk($disk);

    $filesystem = Storage::disk($disk);
    expect($filesystem)->toBeInstanceOf(AwsS3V3Adapter::class);

    $client = $filesystem->getClient();
    if (! $client->doesBucketExistV2($bucket)) {
        $client->createBucket(['Bucket' => $bucket]);
        $client->waitUntil('BucketExists', ['Bucket' => $bucket]);
    }

    $contents = 'nvl-media-production-stack';
    $checksum = hash('sha256', $contents);
    $objectKey = 'media/integration/'.Str::uuid().'.txt';
    $uploadId = (string) Str::uuid();
    $expiresAt = new DateTimeImmutable('+10 minutes');
    $session = new MultipartUploadSessionData(
        uploadId: $uploadId,
        disk: $disk,
        objectKey: $objectKey,
        filename: 'production-stack.txt',
        mimeType: 'text/plain',
        size: strlen($contents),
        checksum: $checksum,
        visibility: MediaVisibility::Private,
        uploaderId: 'integration',
        uploaderType: 'integration',
        expiresAt: $expiresAt,
        minimumPartSize: 1,
        maximumParts: 1,
    );
    $persisted = MediaMultipartUpload::forceCreate([
        'id' => $uploadId,
        'disk' => $disk,
        'object_key' => $objectKey,
        'object_key_hash' => hash('sha256', $objectKey),
        'display_filename' => 'production-stack.txt',
        'canonical_extension' => 'txt',
        'declared_mime' => 'text/plain',
        'expected_size' => strlen($contents),
        'expected_checksum' => $checksum,
        'visibility' => MediaVisibility::Private,
        'uploader_id' => 'integration',
        'uploader_type' => 'integration',
        'expires_at' => $expiresAt,
        'part_size' => strlen($contents),
        'expected_parts' => 1,
        'minimum_part_size' => 1,
        'maximum_part_size' => strlen($contents),
        'maximum_parts' => 1,
        'status' => MediaMultipartStatus::Initiated,
    ]);
    $gateway = new S3MultipartUploadGateway(app(MediaDiskGateway::class));
    $completed = false;

    try {
        $gateway->initiate($session);
        $signed = $gateway->signPart(
            $session,
            new SignMultipartPartData(
                uploadId: $session->uploadId,
                partNumber: 1,
                checksum: $checksum,
                byteLength: strlen($contents),
            ),
        );
        $response = (new Client)->request('PUT', $signed->url, [
            'body' => $contents,
            'headers' => $signed->headers,
            'http_errors' => false,
        ]);

        expect($response->getStatusCode())->toBeGreaterThanOrEqual(200)
            ->toBeLessThan(300);

        $etag = $response->getHeaderLine('ETag');
        expect($etag)->not->toBe('');

        $persisted->forceFill([
            'signed_parts' => [
                1 => [
                    'length' => strlen($contents),
                    'checksum' => $checksum,
                ],
            ],
        ])->save();

        $object = $gateway->complete(
            $session,
            new CompleteMultipartUploadData(
                uploadId: $session->uploadId,
                parts: [new CompletedMultipartPartData(1, $etag)],
            ),
        );
        $completed = true;

        expect($object->path)->toBe($objectKey)
            ->and($object->size)->toBe(strlen($contents))
            ->and($object->checksum)->toBe($checksum)
            ->and($gateway->inspect($session)?->checksum)->toBe($checksum);
    } finally {
        if (! $completed) {
            try {
                $gateway->abort($session);
            } catch (Throwable) {
                // The provider may have already completed or rejected initiation.
            }
        }

        Storage::disk($disk)->delete($objectKey);
    }
});
