<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Contracts\RecoverableMultipartUploadGateway;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Ownership\MediaOwnership;
use Nvl\Media\Support\MediaActors;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Media\Support\MediaHashGenerator;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Logging\PackageLogger;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Throwable;

/**
 * Complete native MediaMultipart operations, including persistence and file effects.
 *
 * @api
 */
final class MediaMultipart
{
    public function __construct(private readonly MediaDiskGuard $diskGuard, private readonly MediaDiskGateway $disks, private readonly MediaPathResolver $paths, private readonly MediaAuthorization $authorization, private readonly MultipartUploadGateway $gateway, private readonly MediaFileTypePolicy $fileTypes, private readonly MediaMultipartSessionMapper $sessionMapper, private readonly OwnershipBoundary $tenantBoundary, private readonly PackageLogger $packageLogger, private readonly MediaFileEffectScheduler $fileEffects, private readonly MediaMultipartLock $lock, private readonly OwnershipWriter $ownershipWriter) {}

    /** Begin one verified upload session.
     * @param  array<string, mixed>  $data
     * @return array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int}
     */
    public function initiate(array $data, Authenticatable|SystemActor $actor): array
    {
        if (array_diff(array_keys($data), ['disk', 'filename', 'mimeType', 'size', 'checksum', 'visibility', 'folder']) !== []) {
            throw new MediaUploadException('Unknown or protected multipart fields.');
        }

        $data += ['visibility' => MediaVisibility::Private, 'folder' => null];
        if (! is_string($data['disk'] ?? null) || ! is_string($data['filename'] ?? null)
            || ! is_string($data['mimeType'] ?? null) || ! is_int($data['size'] ?? null)
            || ! is_string($data['checksum'] ?? null) || ! $data['visibility'] instanceof MediaVisibility
            || ($data['folder'] !== null && ! is_string($data['folder']))) {
            throw new MediaUploadException('Multipart initiation metadata is invalid.');
        }

        $actor = MediaActors::assert($actor);
        $this->initiateAssertEnabled();
        if (! $this->authorization->allows($actor, MediaAbility::Upload)) {
            throw new MediaUploadException('The actor is not authorized to initiate media uploads.');
        }
        if (MediaActors::id($actor) === null) {
            throw new MediaUploadException('Multipart uploads require an identifiable actor.');
        }
        $this->diskGuard->assertAllowed($data['disk']);
        $this->disks->ensureDefined($data['disk']);
        $maximumSize = MediaConfiguration::integer('nvl-media.multipart.maximum_size', 5 * 1024 * 1024 * 1024, 1);
        if ($data['size'] < 1 || $data['size'] > $maximumSize) {
            throw new MediaUploadException('Multipart upload size is outside the configured bounds.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $data['checksum']) !== 1) {
            throw new MediaUploadException('Multipart uploads require a lowercase SHA-256 checksum.');
        }
        $fileType = $this->fileTypes->resolve($data['filename'], $data['mimeType']);
        $minimumPartSize = MediaConfiguration::integer('nvl-media.multipart.minimum_part_size', 5 * 1024 * 1024, 1);
        $maximumPartSize = MediaConfiguration::integer('nvl-media.multipart.maximum_part_size', 5 * 1024 * 1024 * 1024, $minimumPartSize);
        $maximumParts = MediaConfiguration::integer('nvl-media.multipart.maximum_parts', 10000, 1);
        $requiredPartSize = (int) ceil($data['size'] / $maximumParts);
        $partSize = min($maximumPartSize, max($minimumPartSize, $requiredPartSize));
        $expectedParts = (int) ceil($data['size'] / $partSize);
        if ($expectedParts < 1 || $expectedParts > $maximumParts) {
            throw new MediaUploadException('Multipart upload cannot satisfy the configured part bounds.');
        }
        $folder = $this->paths->normalizeFolder($data['folder'] ?? 'pending');
        $objectKey = implode('/', array_filter([$this->paths->storageFolder($folder, $data['disk']), MediaHashGenerator::generateForExtension($fileType['extension'])], static fn (string $part): bool => $part !== ''));
        $session = new MediaMultipartUpload;
        $session->forceFill([...$this->tenantBoundary->attributes('media.multipart'), 'disk' => $data['disk'], 'object_key' => $objectKey, 'object_key_hash' => hash('sha256', $objectKey), 'display_filename' => $fileType['displayFilename'], 'canonical_extension' => $fileType['extension'], 'declared_mime' => $fileType['mimeType'], 'expected_size' => $data['size'], 'expected_checksum' => $data['checksum'], 'visibility' => $data['visibility'], 'uploader_id' => (string) MediaActors::id($actor), 'uploader_type' => MediaActors::type($actor), 'expires_at' => now()->utc()->addMinutes(MediaConfiguration::integer('nvl-media.multipart.session_minutes', 60, 1)), 'part_size' => $partSize, 'expected_parts' => $expectedParts, 'minimum_part_size' => $minimumPartSize, 'maximum_part_size' => $maximumPartSize, 'maximum_parts' => $maximumParts, 'status' => MediaMultipartStatus::Initiated])->save();
        $sessionData = $this->sessionMapper->toData($session);
        try {
            $this->gateway->initiate($sessionData);
        } catch (Throwable $exception) {
            $cleanupException = $this->initiateCleanupFailedInitiation($sessionData);
            $this->initiateRecordInitiationFailure($session, $exception, $cleanupException);
            $this->packageLogger->log('media', 'error', 'nvl.media.multipart_initiation.failed', ['session_id' => $session->id, 'disk' => $session->disk, 'exception' => $exception::class, 'error' => $exception->getMessage(), 'cleanup_pending' => $cleanupException instanceof Throwable], 'normal');
            throw $exception;
        }

        return $this->sessionMapper->toData($session->refresh());
    }

    /**
     * @param  array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int}  $session
     */
    private function initiateCleanupFailedInitiation(array $session): ?Throwable
    {
        try {
            $this->gateway->abort($session);

            return null;
        } catch (Throwable $exception) {
            $this->packageLogger->log('media', 'warning', 'nvl.media.multipart_cleanup.retry', ['session_id' => $session['uploadId'], 'disk' => $session['disk'], 'exception' => $exception::class, 'error' => $exception->getMessage()], 'normal');

            return $exception;
        }
    }

    private function initiateRecordInitiationFailure(MediaMultipartUpload $session, Throwable $exception, ?Throwable $cleanupException): void
    {
        $cleanupPending = $cleanupException instanceof Throwable;
        $attributes = ['status' => $cleanupPending ? MediaMultipartStatus::Failed : MediaMultipartStatus::Aborted, 'failure_code' => $cleanupPending ? 'provider_initiation_cleanup_pending' : 'provider_initiation_failed', 'failure_context' => ['exception' => $exception::class, 'message' => mb_substr($exception->getMessage(), 0, 1000), 'cleanup_exception' => $cleanupException instanceof Throwable ? $cleanupException::class : null, 'cleanup_message' => $cleanupException instanceof Throwable ? mb_substr($cleanupException->getMessage(), 0, 1000) : null]];
        if ($cleanupPending) {
            $attributes['expires_at'] = now()->utc();
        } else {
            $attributes['provider_state'] = null;
        }
        try {
            $session->forceFill($attributes)->save();
        } catch (Throwable $persistenceException) {
            $this->packageLogger->log('media', 'error', 'nvl.media.multipart_failure_state.failed', ['session_id' => $session->id, 'disk' => $session->disk, 'exception' => $persistenceException::class, 'error' => $persistenceException->getMessage()], 'normal');
        }
    }

    private function initiateAssertEnabled(): void
    {
        if (! (bool) config('nvl-media.multipart.enabled', false)) {
            throw new MediaUploadException('Multipart uploads are disabled.');
        }
    }

    /** Sign a bounded, checksum-bound part of an actor-owned session.
     * @param  array<string, mixed>  $part
     * @return array{partNumber: int, url: string, headers: array<string, string>, expiresAt: \DateTimeInterface}
     */
    public function signPart(array $part, Authenticatable|SystemActor $actor): array
    {
        if (array_diff(array_keys($part), ['uploadId', 'partNumber', 'checksum', 'byteLength']) !== []) {
            throw new MediaUploadException('Unknown or protected multipart fields.');
        }

        if (! is_string($part['uploadId'] ?? null) || ! Str::isUuid($part['uploadId'])
            || ! is_int($part['partNumber'] ?? null) || ! is_int($part['byteLength'] ?? null)
            || ! is_string($part['checksum'] ?? null)) {
            throw new MediaUploadException('Multipart part metadata is invalid.');
        }

        $actor = MediaActors::assert($actor);
        $this->signPartAssertEnabled();
        $expired = false;
        $session = MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($part, $actor, &$expired): MediaMultipartUpload {
            $session = MediaMultipartUpload::query()->lockForUpdate()->findOrFail($part['uploadId']);
            $this->signPartAssertActor($session, $actor);
            if ($session->status !== MediaMultipartStatus::Initiated) {
                throw new MediaUploadException("Multipart session is not active; status is [{$session->status->value}].");
            }
            if ($session->expires_at->isPast()) {
                $session->status = MediaMultipartStatus::Expired;
                $session->save();
                $expired = true;

                return $session;
            }
            if ($part['partNumber'] < 1 || $part['partNumber'] > $session->expected_parts) {
                throw new MediaUploadException('Multipart part number is outside the session bounds.');
            }
            if ($part['byteLength'] !== $this->signPartExpectedLength($session, $part['partNumber'])) {
                throw new MediaUploadException('Multipart part byte length does not match its session bounds.');
            }
            if (preg_match('/^[a-f0-9]{64}$/', $part['checksum']) !== 1) {
                throw new MediaUploadException('Multipart parts require a lowercase SHA-256 checksum.');
            }
            $signedParts = is_array($session->signed_parts) ? $session->signed_parts : [];
            $existing = $signedParts[$part['partNumber']] ?? null;
            $expected = ['length' => $part['byteLength'], 'checksum' => $part['checksum']];
            if ($existing !== null && $existing !== $expected) {
                throw new MediaUploadException("Multipart part [{$part['partNumber']}] was already signed with different metadata.");
            }
            $signedParts[$part['partNumber']] = $expected;
            $session->signed_parts = $signedParts;
            $session->save();

            return $session;
        });
        if ($expired) {
            throw new MediaUploadException('Multipart upload session has expired.');
        }

        return $this->gateway->signPart($this->sessionMapper->toData($session), ['uploadId' => $session->id, 'partNumber' => $part['partNumber'], 'checksum' => $part['checksum'], 'byteLength' => $part['byteLength']]);
    }

    private function signPartAssertEnabled(): void
    {
        if (! (bool) config('nvl-media.multipart.enabled', false)) {
            throw new MediaUploadException('Multipart uploads are disabled.');
        }
    }

    private function signPartExpectedLength(MediaMultipartUpload $session, int $partNumber): int
    {
        if ($partNumber < $session->expected_parts) {
            return $session->part_size;
        }

        return $session->expected_size - $session->part_size * ($session->expected_parts - 1);
    }

    private function signPartAssertActor(MediaMultipartUpload $session, Authenticatable|SystemActor $actor): void
    {
        if ((string) $session->uploader_id !== (string) MediaActors::id($actor) || $session->uploader_type !== MediaActors::type($actor)) {
            throw new MediaUploadException('Multipart session actor mismatch.');
        }
    }

    /** Verify provider completion and return the idempotent persisted asset.
     * @param  array<string, mixed>  $completion
     */
    public function complete(array $completion, Authenticatable|SystemActor $actor): Media
    {
        if (array_diff(array_keys($completion), ['uploadId', 'parts']) !== []) {
            throw new MediaUploadException('Unknown or protected multipart fields.');
        }

        if (! is_string($completion['uploadId'] ?? null) || ! Str::isUuid($completion['uploadId'])
            || ! is_array($completion['parts'] ?? null) || ! array_is_list($completion['parts'])
            || count($completion['parts']) > 10000) {
            throw new MediaUploadException('Multipart completion metadata is invalid.');
        }
        $parts = [];
        foreach ($completion['parts'] as $part) {
            if (! is_array($part) || array_diff(array_keys($part), ['partNumber', 'etag', 'checksum']) !== []
                || ! is_int($part['partNumber'] ?? null) || ! is_string($part['etag'] ?? null)
                || trim($part['etag']) === '' || (isset($part['checksum']) && ! is_string($part['checksum']))) {
                throw new MediaUploadException('Multipart completion receipts are invalid.');
            }
            $parts[] = ['partNumber' => $part['partNumber'], 'etag' => $part['etag'], 'checksum' => $part['checksum'] ?? null];
        }
        $completion = ['uploadId' => $completion['uploadId'], 'parts' => $parts];

        $actor = MediaActors::assert($actor);
        $this->completeAssertEnabled();

        return $this->lock->execute($completion['uploadId'], fn (): Media => $this->completeCompleteLocked($completion, $actor));
    }

    /**
     * @param  array{uploadId: string, parts: list<array{partNumber: int, etag: string, checksum: string|null}>}  $completion
     */
    private function completeCompleteLocked(array $completion, Authenticatable|SystemActor $actor): Media
    {
        $parts = $this->completeSortedParts($completion['parts']);
        $expired = false;
        $session = MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($completion, $actor, $parts, &$expired): MediaMultipartUpload {
            $session = MediaMultipartUpload::query()->lockForUpdate()->findOrFail($completion['uploadId']);
            $this->completeAssertActor($session, $actor);
            if ($session->status === MediaMultipartStatus::Completed) {
                return $session;
            }
            if ($session->expires_at->isPast()) {
                $session->status = MediaMultipartStatus::Expired;
                $session->save();
                $expired = true;

                return $session;
            }
            if (! in_array($session->status, [MediaMultipartStatus::Initiated, MediaMultipartStatus::Completing], true)) {
                throw new MediaUploadException("Multipart session cannot complete from status [{$session->status->value}].");
            }
            $this->completeAssertParts($session, $parts);
            $session->status = MediaMultipartStatus::Completing;
            $session->failure_code = null;
            $session->failure_context = null;
            $session->save();

            return $session;
        });
        if ($expired) {
            throw new MediaUploadException('Multipart upload session has expired.');
        }
        if ($session->status === MediaMultipartStatus::Completed) {
            return $session->completedMedia()->firstOrFail();
        }
        $sessionData = $this->sessionMapper->toData($session);
        $authoritativeCompletion = ['uploadId' => $session->id, 'parts' => $parts];
        try {
            $object = $this->gateway->complete($sessionData, $authoritativeCompletion);
        } catch (Throwable $exception) {
            $object = $this->completeRecoverCompletedObject($sessionData, $exception);
        }
        if (! $this->completeObjectMatches($session, $object)) {
            $this->completeBestEffortAbort($sessionData);
            $this->fileEffects->deleteNow($session->disk, [$session->object_key], 'multipart_completed_object_mismatch');
            $this->completeMarkFailed($session, 'completed_object_mismatch', ['actual_path' => $object['path'], 'actual_size' => $object['size'], 'actual_checksum' => $object['checksum'], 'object_identity' => $object['objectIdentity']]);
            throw new MediaUploadException('Completed multipart object failed path, checksum, or size verification.');
        }
        MediaMultipartUpload::query()->whereKey($session->id)->where('status', MediaMultipartStatus::Completing->value)->update(['provider_object_identity' => $object['objectIdentity']]);
        $session->provider_object_identity = $object['objectIdentity'];

        return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($session, $object): Media {
            $locked = MediaMultipartUpload::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status === MediaMultipartStatus::Completed) {
                return $locked->completedMedia()->firstOrFail();
            }
            if ($locked->status !== MediaMultipartStatus::Completing) {
                throw new MediaUploadException("Multipart session finalization lost its completing state [{$locked->status->value}].");
            }
            $pathInfo = pathinfo($locked->object_key);
            $folder = $this->paths->logicalFolderFromStoragePath($locked->object_key, $locked->disk);
            $media = MediaModelRegistry::query()->firstOrNew(['upload_session_id' => $locked->id]);
            if (! $media->exists) {
                $media->forceFill([...MediaOwnership::active() ? ['storage_path' => $locked->object_key] : [], 'filename' => $locked->display_filename, 'hash' => (string) $pathInfo['basename'], 'extension' => $locked->canonical_extension, 'mime_type' => $locked->declared_mime, 'size' => $locked->expected_size, 'disk' => $locked->disk, 'folder' => $folder !== '' && $folder !== '.' ? $folder : null, 'visibility' => $locked->visibility, 'status' => MediaLifecycleStatus::PendingScan, 'available_at' => null, 'type' => MediaType::fromExtension($locked->canonical_extension), 'digest' => $locked->expected_checksum, 'uploaded_by' => $locked->uploader_id, 'uploaded_by_type' => $locked->uploader_type]);
                $this->ownershipWriter->create(MediaModelRegistry::modelClass(), static function (array $attributes) use ($media): Media {
                    $media->forceFill($attributes)->save();

                    return $media;
                });
            }
            $locked->forceFill(['status' => MediaMultipartStatus::Completed, 'completed_media_id' => $media->id, 'provider_object_identity' => $object['objectIdentity'], 'failure_code' => null, 'failure_context' => null])->save();

            return $media;
        });
    }

    /**
     * @param  list<array{partNumber: int, etag: string, checksum?: string|null}>  $parts
     * @return list<array{partNumber: int, etag: string, checksum?: string|null}>
     */
    private function completeSortedParts(array $parts): array
    {
        usort($parts, static fn (array $left, array $right): int => $left['partNumber'] <=> $right['partNumber']);

        return $parts;
    }

    /**
     * @param  list<array{partNumber: int, etag: string, checksum?: string|null}>  $parts
     */
    private function completeAssertParts(MediaMultipartUpload $session, array $parts): void
    {
        if (count($parts) !== $session->expected_parts) {
            throw new MediaUploadException('Multipart completion contains an invalid part count.');
        }
        $partNumbers = array_map(static fn (array $part): int => $part['partNumber'], $parts);
        $expectedNumbers = range(1, $session->expected_parts);
        if ($partNumbers !== $expectedNumbers) {
            throw new MediaUploadException('Multipart completion requires each expected part exactly once.');
        }
        $signedParts = is_array($session->signed_parts) ? $session->signed_parts : [];
        foreach ($expectedNumbers as $partNumber) {
            if (! isset($signedParts[$partNumber])) {
                throw new MediaUploadException("Multipart part [{$partNumber}] was not signed by this session.");
            }
        }
    }

    /**
     * @param  array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int}  $session
     * @return array{path: string, checksum: string, size: int, mimeType: string, objectIdentity: string|null}
     */
    private function completeRecoverCompletedObject(array $session, Throwable $exception): array
    {
        if ($this->gateway instanceof RecoverableMultipartUploadGateway) {
            $recovered = $this->gateway->inspect($session);
            if (is_array($recovered)) {
                $this->packageLogger->log('media', 'warning', 'nvl.media.multipart_completion.recovered', ['session_id' => $session['uploadId'], 'exception' => $exception::class, 'error' => $exception->getMessage()], 'normal');

                return $recovered;
            }
        }
        $this->packageLogger->log('media', 'error', 'nvl.media.multipart_completion.pending', ['session_id' => $session['uploadId'], 'exception' => $exception::class, 'error' => $exception->getMessage()], 'normal');
        throw $exception;
    }

    /**
     * @param  array{path: string, checksum: string, size: int, mimeType: string, objectIdentity: string|null}  $object
     */
    private function completeObjectMatches(MediaMultipartUpload $session, array $object): bool
    {
        if (! is_string($object['objectIdentity']) || $object['objectIdentity'] === '') {
            return false;
        }
        if (is_string($session->provider_object_identity) && $session->provider_object_identity !== '' && ! hash_equals($session->provider_object_identity, $object['objectIdentity'])) {
            return false;
        }

        return hash_equals($session->object_key, $object['path']) && hash_equals($session->expected_checksum, mb_strtolower($object['checksum'])) && $session->expected_size === $object['size'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function completeMarkFailed(MediaMultipartUpload $session, string $code, array $context): void
    {
        MediaMultipartUpload::query()->whereKey($session->id)->update(['status' => MediaMultipartStatus::Failed->value, 'failure_code' => $code, 'failure_context' => json_encode($context, JSON_THROW_ON_ERROR)]);
        $this->packageLogger->log('media', 'error', 'nvl.media.multipart_verification.failed', ['session_id' => $session->id, 'failure_code' => $code, ...$context], 'normal');
    }

    /**
     * @param  array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int}  $session
     */
    private function completeBestEffortAbort(array $session): void
    {
        try {
            $this->gateway->abort($session);
        } catch (Throwable $exception) {
            $this->packageLogger->log('media', 'warning', 'nvl.media.multipart_cleanup.failed', ['session_id' => $session['uploadId'], 'exception' => $exception::class, 'error' => $exception->getMessage()], 'normal');
        }
    }

    private function completeAssertActor(MediaMultipartUpload $session, Authenticatable|SystemActor $actor): void
    {
        if ((string) $session->uploader_id !== (string) MediaActors::id($actor) || $session->uploader_type !== MediaActors::type($actor)) {
            throw new MediaUploadException('Multipart session actor mismatch.');
        }
    }

    private function completeAssertEnabled(): void
    {
        if (! (bool) config('nvl-media.multipart.enabled', false)) {
            throw new MediaUploadException('Multipart uploads are disabled.');
        }
    }

    public function abort(string $uploadId, Authenticatable|SystemActor $actor): void
    {
        $actor = MediaActors::assert($actor);
        $this->abortAssertEnabled();
        $this->lock->execute($uploadId, function () use ($uploadId, $actor): void {
            $persisted = MediaMultipartUpload::query()->findOrFail($uploadId);
            $this->abortAssertActor($persisted, $actor);
            if ($persisted->status === MediaMultipartStatus::Aborted) {
                return;
            }
            if ($persisted->status === MediaMultipartStatus::Completed) {
                throw new MediaUploadException('A completed multipart upload cannot be aborted.');
            }
            $this->gateway->abort($this->sessionMapper->toData($persisted));
            MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($persisted): void {
                $locked = MediaMultipartUpload::query()->lockForUpdate()->findOrFail($persisted->id);
                if ($locked->status !== MediaMultipartStatus::Completed) {
                    $locked->forceFill(['status' => MediaMultipartStatus::Aborted, 'provider_state' => null, 'failure_code' => null, 'failure_context' => null])->save();
                }
            });
        });
    }

    private function abortAssertActor(MediaMultipartUpload $session, Authenticatable|SystemActor $actor): void
    {
        if ((string) $session->uploader_id !== (string) MediaActors::id($actor) || $session->uploader_type !== MediaActors::type($actor)) {
            throw new MediaUploadException('Multipart session actor mismatch.');
        }
    }

    private function abortAssertEnabled(): void
    {
        if (! (bool) config('nvl-media.multipart.enabled', false)) {
            throw new MediaUploadException('Multipart uploads are disabled.');
        }
    }
}
