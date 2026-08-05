<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\Log;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\Multipart\InitiateMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDiskGuard;
use Nvl\Media\Services\MediaFileTypePolicy;
use Nvl\Media\Services\MediaMultipartSessionMapper;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Support\MediaHashGenerator;
use Throwable;

/**
 * Creates a server-owned multipart upload session before invoking the provider.
 */
final readonly class InitiateMultipartUploadAction
{
    public function __construct(
        private MediaDiskGuard $diskGuard,
        private MediaDiskGateway $disks,
        private MediaPathResolver $paths,
        private MediaAuthorization $authorization,
        private MultipartUploadGateway $gateway,
        private MediaFileTypePolicy $fileTypes,
        private MediaMultipartSessionMapper $sessionMapper,
    ) {}

    public function execute(
        InitiateMultipartUploadData $data,
        MediaActorData $actor,
    ): MultipartUploadSessionData {
        $this->assertEnabled();

        if (! $this->authorization->allows($actor, MediaAbility::Upload)) {
            throw new MediaUploadException('The actor is not authorized to initiate media uploads.');
        }

        if ($actor->id === null || $actor->type === null) {
            throw new MediaUploadException('Multipart uploads require an identifiable actor.');
        }

        $this->diskGuard->assertAllowed($data->disk);
        $this->disks->ensureDefined($data->disk);
        $maximumSize = MediaConfiguration::integer(
            'media.multipart.maximum_size',
            5 * 1024 * 1024 * 1024,
            1,
        );

        if ($data->size < 1 || $data->size > $maximumSize) {
            throw new MediaUploadException('Multipart upload size is outside the configured bounds.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $data->checksum) !== 1) {
            throw new MediaUploadException('Multipart uploads require a lowercase SHA-256 checksum.');
        }

        $fileType = $this->fileTypes->resolve($data->filename, $data->mimeType);
        $minimumPartSize = MediaConfiguration::integer(
            'media.multipart.minimum_part_size',
            5 * 1024 * 1024,
            1,
        );
        $maximumPartSize = MediaConfiguration::integer(
            'media.multipart.maximum_part_size',
            5 * 1024 * 1024 * 1024,
            $minimumPartSize,
        );
        $maximumParts = MediaConfiguration::integer(
            'media.multipart.maximum_parts',
            10_000,
            1,
        );
        $requiredPartSize = (int) ceil($data->size / $maximumParts);
        $partSize = min($maximumPartSize, max($minimumPartSize, $requiredPartSize));
        $expectedParts = (int) ceil($data->size / $partSize);

        if ($expectedParts < 1 || $expectedParts > $maximumParts) {
            throw new MediaUploadException('Multipart upload cannot satisfy the configured part bounds.');
        }

        $folder = $this->paths->normalizeFolder($data->folder ?? 'pending');
        $objectKey = implode('/', array_filter([
            trim(MediaConfiguration::string('media.root_folder', 'media'), '/'),
            $folder,
            MediaHashGenerator::generateForExtension($fileType->extension),
        ]));
        $session = MediaMultipartUpload::query()->create([
            'disk' => $data->disk,
            'object_key' => $objectKey,
            'object_key_hash' => hash('sha256', $objectKey),
            'display_filename' => $fileType->displayFilename,
            'canonical_extension' => $fileType->extension,
            'declared_mime' => $fileType->mimeType,
            'expected_size' => $data->size,
            'expected_checksum' => $data->checksum,
            'visibility' => $data->visibility,
            'uploader_id' => (string) $actor->id,
            'uploader_type' => $actor->type,
            'expires_at' => now()->addMinutes(
                MediaConfiguration::integer('media.multipart.session_minutes', 60, 1),
            ),
            'part_size' => $partSize,
            'expected_parts' => $expectedParts,
            'minimum_part_size' => $minimumPartSize,
            'maximum_part_size' => $maximumPartSize,
            'maximum_parts' => $maximumParts,
            'status' => MediaMultipartStatus::Initiated,
        ]);
        $sessionData = $this->sessionMapper->toData($session);

        try {
            $this->gateway->initiate($sessionData);
        } catch (Throwable $exception) {
            $cleanupException = $this->cleanupFailedInitiation($sessionData);
            $this->recordInitiationFailure($session, $exception, $cleanupException);
            Log::error('Multipart provider initiation failed.', [
                'session_id' => $session->id,
                'disk' => $session->disk,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
                'cleanup_pending' => $cleanupException instanceof Throwable,
            ]);

            throw $exception;
        }

        return $this->sessionMapper->toData($session->refresh());
    }

    private function cleanupFailedInitiation(
        MultipartUploadSessionData $session,
    ): ?Throwable {
        try {
            $this->gateway->abort($session);

            return null;
        } catch (Throwable $exception) {
            Log::warning('Multipart provider initiation cleanup requires retry.', [
                'session_id' => $session->uploadId,
                'disk' => $session->disk,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return $exception;
        }
    }

    private function recordInitiationFailure(
        MediaMultipartUpload $session,
        Throwable $exception,
        ?Throwable $cleanupException,
    ): void {
        $cleanupPending = $cleanupException instanceof Throwable;
        $attributes = [
            'status' => $cleanupPending
                ? MediaMultipartStatus::Failed
                : MediaMultipartStatus::Aborted,
            'failure_code' => $cleanupPending
                ? 'provider_initiation_cleanup_pending'
                : 'provider_initiation_failed',
            'failure_context' => [
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 1000),
                'cleanup_exception' => $cleanupException instanceof Throwable
                    ? $cleanupException::class
                    : null,
                'cleanup_message' => $cleanupException instanceof Throwable
                    ? mb_substr($cleanupException->getMessage(), 0, 1000)
                    : null,
            ],
        ];

        if ($cleanupPending) {
            $attributes['expires_at'] = now();
        } else {
            $attributes['provider_state'] = null;
        }

        try {
            $session->forceFill($attributes)->save();
        } catch (Throwable $persistenceException) {
            Log::error('Multipart initiation failure state could not be persisted.', [
                'session_id' => $session->id,
                'disk' => $session->disk,
                'exception' => $persistenceException::class,
                'error' => $persistenceException->getMessage(),
            ]);
        }
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('media.multipart.enabled', false)) {
            throw new MediaUploadException('Multipart uploads are disabled.');
        }
    }
}
