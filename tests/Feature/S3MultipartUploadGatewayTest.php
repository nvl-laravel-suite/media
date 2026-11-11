<?php

declare(strict_types=1);
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Services\S3MultipartUploadGateway;
use Nvl\Support\Logging\PackageLogger;

const MULTIPART_S3_TEST_DISK = 'multipart-s3-test';
const MULTIPART_S3_TEST_BUCKET = 'multipart-test-bucket';
beforeEach(function (): void {
    Storage::forgetDisk(MULTIPART_S3_TEST_DISK);
});
afterEach(function (): void {
    Storage::forgetDisk(MULTIPART_S3_TEST_DISK);
});
function configureMultipartS3TestDisk(MockHandler $handler): string
{
    config(['filesystems.disks.'.MULTIPART_S3_TEST_DISK => ['driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret', 'region' => 'us-east-1', 'bucket' => MULTIPART_S3_TEST_BUCKET, 'handler' => $handler, 'use_path_style_endpoint' => true]]);
    Storage::forgetDisk(MULTIPART_S3_TEST_DISK);

    return MULTIPART_S3_TEST_DISK;
}
/**
 * @param  array<string, mixed>|null  $providerState
 * @return array{array<string, mixed>, MediaMultipartUpload}
 */
function createMultipartS3TestSession(string $disk, string $contents, ?array $providerState = null): array
{
    $uploadId = (string) Str::uuid();
    $objectKey = 'media/pending/'.Str::uuid().'.txt';
    $expiresAt = new DateTimeImmutable('+10 minutes');
    $checksum = hash('sha256', $contents);
    $session = ['uploadId' => $uploadId, 'disk' => $disk, 'objectKey' => $objectKey, 'filename' => 'multipart.txt', 'mimeType' => 'text/plain', 'size' => strlen($contents), 'checksum' => $checksum, 'visibility' => MediaVisibility::Private, 'uploaderId' => 's3-test-actor', 'uploaderType' => 's3-test-actor', 'expiresAt' => $expiresAt, 'minimumPartSize' => 1, 'maximumParts' => 1];
    $persisted = MediaMultipartUpload::forceCreate(['id' => $uploadId, 'provider_state' => $providerState, 'disk' => $disk, 'object_key' => $objectKey, 'object_key_hash' => hash('sha256', $objectKey), 'display_filename' => 'multipart.txt', 'canonical_extension' => 'txt', 'declared_mime' => 'text/plain', 'expected_size' => strlen($contents), 'expected_checksum' => $checksum, 'visibility' => MediaVisibility::Private, 'uploader_id' => 's3-test-actor', 'uploader_type' => 's3-test-actor', 'expires_at' => $expiresAt, 'part_size' => strlen($contents), 'expected_parts' => 1, 'minimum_part_size' => 1, 'maximum_part_size' => strlen($contents), 'maximum_parts' => 1, 'status' => MediaMultipartStatus::Initiated]);

    return [$session, $persisted];
}
it('does not read a composite-checksum object back and leaves its SHA-256 to scan finalisation', function () {
    $contents = 'composite-provider-object';
    $commands = [];
    $handler = new MockHandler([function (CommandInterface $command) use (&$commands, $contents): Result {
        $commands[] = $command->getName();

        return new Result(['ContentLength' => strlen($contents), 'ContentType' => 'text/plain', 'ChecksumSHA256' => base64_encode(hash('sha256', 'part-checksum', true)).'-1', 'ChecksumType' => 'COMPOSITE', 'ETag' => '"composite-etag-1"']);
    }]);
    $disk = configureMultipartS3TestDisk($handler);
    [$session] = createMultipartS3TestSession($disk, $contents);
    $object = (new S3MultipartUploadGateway(app(PackageLogger::class)))->inspect($session);
    expect($object)->not->toBeNull()->and($object['checksum'])->toBeNull()->and($object['size'])->toBe(strlen($contents))->and($object['objectIdentity'])->toBe('composite-etag-1')->and($commands)->toBe(['HeadObject']);
});
it('reports the provider SHA-256 when S3 attests a full-object checksum', function () {
    $contents = 'full-object-provider-checksum';
    $handler = new MockHandler([fn (CommandInterface $command): Result => new Result(['ContentLength' => strlen($contents), 'ContentType' => 'text/plain', 'ChecksumSHA256' => base64_encode(hash('sha256', $contents, true)), 'ChecksumType' => 'FULL_OBJECT', 'ETag' => '"full-etag"'])]);
    $disk = configureMultipartS3TestDisk($handler);
    [$session] = createMultipartS3TestSession($disk, $contents);
    $object = (new S3MultipartUploadGateway(app(PackageLogger::class)))->inspect($session);
    expect($object['checksum'] ?? null)->toBe(hash('sha256', $contents))->and(count($handler))->toBe(0);
});
it('treats AWS NoSuchUpload as an idempotent abort success', function () {
    $commands = [];
    $handler = new MockHandler([function (CommandInterface $command) use (&$commands): S3Exception {
        $commands[] = $command->getName();

        return new S3Exception('The multipart upload no longer exists.', $command, ['code' => 'NoSuchUpload']);
    }]);
    $disk = configureMultipartS3TestDisk($handler);
    $contents = 'already-aborted';
    [$session, $persisted] = createMultipartS3TestSession($disk, $contents);
    $persisted->forceFill(['provider_state' => ['upload_id' => 'provider-upload-id', 'bucket' => MULTIPART_S3_TEST_BUCKET, 'key' => $session['objectKey']]])->save();
    (new S3MultipartUploadGateway(app(PackageLogger::class)))->abort($session);
    expect($commands)->toBe(['AbortMultipartUpload'])->and(count($handler))->toBe(0);
});
it('discovers and aborts only exact-key provider uploads when provider state was not persisted', function () {
    $commands = [];
    $abortedUploadId = null;
    $handler = new MockHandler([function (CommandInterface $command) use (&$commands): Result {
        $commands[] = $command->getName();
        $key = (string) $command['Prefix'];

        return new Result(['IsTruncated' => false, 'Uploads' => [['Key' => $key, 'UploadId' => 'orphan-provider-upload'], ['Key' => $key.'-unrelated', 'UploadId' => 'unrelated-provider-upload']]]);
    }, function (CommandInterface $command) use (&$commands, &$abortedUploadId): Result {
        $commands[] = $command->getName();
        $abortedUploadId = $command['UploadId'];

        return new Result;
    }]);
    $disk = configureMultipartS3TestDisk($handler);
    [$session] = createMultipartS3TestSession($disk, 'orphan-provider-state');
    (new S3MultipartUploadGateway(app(PackageLogger::class)))->abort($session);
    expect($commands)->toBe(['ListMultipartUploads', 'AbortMultipartUpload'])->and($abortedUploadId)->toBe('orphan-provider-upload')->and(count($handler))->toBe(0);
});
it('compensates immediately when the provider upload id cannot be persisted', function () {
    $commands = [];
    $handler = new MockHandler([function (CommandInterface $command) use (&$commands): Result {
        $commands[] = $command->getName();
        MediaMultipartUpload::query()->delete();

        return new Result(['UploadId' => 'unpersisted-provider-upload']);
    }, function (CommandInterface $command) use (&$commands): Result {
        $commands[] = $command->getName();

        return new Result;
    }]);
    $disk = configureMultipartS3TestDisk($handler);
    [$session] = createMultipartS3TestSession($disk, 'provider-state-persistence');
    expect(fn () => (new S3MultipartUploadGateway(app(PackageLogger::class)))->initiate($session))->toThrow(ModelNotFoundException::class);
    expect($commands)->toBe(['CreateMultipartUpload', 'AbortMultipartUpload'])->and(count($handler))->toBe(0);
});
