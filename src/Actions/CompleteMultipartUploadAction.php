<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Contracts\RecoverableMultipartUploadGateway;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\Multipart\CompletedMultipartObjectData;
use Nvl\Media\Data\Multipart\CompletedMultipartPartData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaMultipartLock;
use Nvl\Media\Services\MediaMultipartSessionMapper;
use Nvl\Media\Support\MediaConfiguration;
use Throwable;

/**
 * Completes and recovers a persisted direct-upload session idempotently.
 */
final readonly class CompleteMultipartUploadAction
{
    public function __construct(
        private MultipartUploadGateway $gateway,
        private MediaFileEffectScheduler $fileEffects,
        private MediaMultipartLock $lock,
        private MediaMultipartSessionMapper $sessionMapper,
    ) {}

    public function execute(
        CompleteMultipartUploadData $completion,
        MediaActorData $actor,
    ): Media {
        $this->assertEnabled();

        return $this->lock->execute(
            $completion->uploadId,
            fn (): Media => $this->completeLocked($completion, $actor),
        );
    }

    private function completeLocked(
        CompleteMultipartUploadData $completion,
        MediaActorData $actor,
    ): Media {
        $parts = $this->sortedParts($completion->parts);
        $expired = false;
        $session = DB::transaction(function () use ($completion, $actor, $parts, &$expired): MediaMultipartUpload {
            $session = MediaMultipartUpload::query()
                ->lockForUpdate()
                ->findOrFail($completion->uploadId);
            $this->assertActor($session, $actor);

            if ($session->status === MediaMultipartStatus::Completed) {
                return $session;
            }

            if ($session->expires_at->isPast()) {
                $session->status = MediaMultipartStatus::Expired;
                $session->save();
                $expired = true;

                return $session;
            }

            if (! in_array(
                $session->status,
                [MediaMultipartStatus::Initiated, MediaMultipartStatus::Completing],
                true,
            )) {
                throw new MediaUploadException(
                    "Multipart session cannot complete from status [{$session->status->value}].",
                );
            }

            $this->assertParts($session, $parts);
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
        $authoritativeCompletion = new CompleteMultipartUploadData($session->id, $parts);

        try {
            $object = $this->gateway->complete($sessionData, $authoritativeCompletion);
        } catch (Throwable $exception) {
            $object = $this->recoverCompletedObject($sessionData, $exception);
        }

        if (! $this->objectMatches($session, $object)) {
            $this->bestEffortAbort($sessionData);
            $this->fileEffects->deleteNow(
                $session->disk,
                [$session->object_key],
                'multipart_completed_object_mismatch',
            );
            $this->markFailed($session, 'completed_object_mismatch', [
                'actual_path' => $object->path,
                'actual_size' => $object->size,
                'actual_checksum' => $object->checksum,
                'object_identity' => $object->objectIdentity,
            ]);

            throw new MediaUploadException(
                'Completed multipart object failed path, checksum, or size verification.',
            );
        }

        MediaMultipartUpload::query()
            ->whereKey($session->id)
            ->where('status', MediaMultipartStatus::Completing->value)
            ->update(['provider_object_identity' => $object->objectIdentity]);
        $session->provider_object_identity = $object->objectIdentity;

        return DB::transaction(function () use ($session, $object): Media {
            $locked = MediaMultipartUpload::query()
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($locked->status === MediaMultipartStatus::Completed) {
                return $locked->completedMedia()->firstOrFail();
            }

            if ($locked->status !== MediaMultipartStatus::Completing) {
                throw new MediaUploadException(
                    "Multipart session finalization lost its completing state [{$locked->status->value}].",
                );
            }

            $pathInfo = pathinfo($locked->object_key);
            $folder = (string) ($pathInfo['dirname'] ?? '');
            $root = trim(MediaConfiguration::string('media.root_folder', 'media'), '/');

            if ($root !== '' && ($folder === $root || str_starts_with($folder, $root.'/'))) {
                $folder = ltrim(substr($folder, strlen($root)), '/');
            }

            $media = Media::query()->firstOrCreate([
                'upload_session_id' => $locked->id,
            ], [
                'filename' => $locked->display_filename,
                'hash' => (string) $pathInfo['basename'],
                'extension' => $locked->canonical_extension,
                'mime_type' => $locked->declared_mime,
                'size' => $locked->expected_size,
                'disk' => $locked->disk,
                'folder' => $folder !== '' && $folder !== '.' ? $folder : null,
                'visibility' => $locked->visibility,
                'status' => MediaLifecycleStatus::PendingScan,
                'available_at' => null,
                'type' => MediaType::fromExtension($locked->canonical_extension),
                'digest' => $locked->expected_checksum,
                'uploaded_by' => $locked->uploader_id,
                'uploaded_by_type' => $locked->uploader_type,
            ]);

            $locked->forceFill([
                'status' => MediaMultipartStatus::Completed,
                'completed_media_id' => $media->id,
                'provider_object_identity' => $object->objectIdentity,
                'failure_code' => null,
                'failure_context' => null,
            ])->save();

            return $media;
        });
    }

    /**
     * @param  list<CompletedMultipartPartData>  $parts
     * @return list<CompletedMultipartPartData>
     */
    private function sortedParts(array $parts): array
    {
        usort(
            $parts,
            static fn (CompletedMultipartPartData $left, CompletedMultipartPartData $right): int => $left->partNumber <=> $right->partNumber,
        );

        return $parts;
    }

    /**
     * @param  list<CompletedMultipartPartData>  $parts
     */
    private function assertParts(MediaMultipartUpload $session, array $parts): void
    {
        if (count($parts) !== $session->expected_parts) {
            throw new MediaUploadException('Multipart completion contains an invalid part count.');
        }

        $partNumbers = array_map(
            static fn (CompletedMultipartPartData $part): int => $part->partNumber,
            $parts,
        );
        $expectedNumbers = range(1, $session->expected_parts);

        if ($partNumbers !== $expectedNumbers) {
            throw new MediaUploadException(
                'Multipart completion requires each expected part exactly once.',
            );
        }

        $signedParts = is_array($session->signed_parts) ? $session->signed_parts : [];

        foreach ($expectedNumbers as $partNumber) {
            if (! isset($signedParts[$partNumber])) {
                throw new MediaUploadException(
                    "Multipart part [{$partNumber}] was not signed by this session.",
                );
            }
        }
    }

    private function recoverCompletedObject(
        MultipartUploadSessionData $session,
        Throwable $exception,
    ): CompletedMultipartObjectData {
        if ($this->gateway instanceof RecoverableMultipartUploadGateway) {
            $recovered = $this->gateway->inspect($session);

            if ($recovered instanceof CompletedMultipartObjectData) {
                Log::warning('Recovered multipart object after interrupted completion.', [
                    'session_id' => $session->uploadId,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                return $recovered;
            }
        }

        Log::error('Multipart completion requires recovery.', [
            'session_id' => $session->uploadId,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);

        throw $exception;
    }

    private function objectMatches(
        MediaMultipartUpload $session,
        CompletedMultipartObjectData $object,
    ): bool {
        if (! is_string($object->objectIdentity) || $object->objectIdentity === '') {
            return false;
        }

        if (is_string($session->provider_object_identity)
            && $session->provider_object_identity !== ''
            && ! hash_equals($session->provider_object_identity, $object->objectIdentity)) {
            return false;
        }

        return hash_equals($session->object_key, $object->path)
            && hash_equals($session->expected_checksum, mb_strtolower($object->checksum))
            && $session->expected_size === $object->size;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function markFailed(
        MediaMultipartUpload $session,
        string $code,
        array $context,
    ): void {
        MediaMultipartUpload::query()
            ->whereKey($session->id)
            ->update([
                'status' => MediaMultipartStatus::Failed->value,
                'failure_code' => $code,
                'failure_context' => json_encode($context, JSON_THROW_ON_ERROR),
            ]);

        Log::error('Multipart session failed verification.', [
            'session_id' => $session->id,
            'failure_code' => $code,
            ...$context,
        ]);
    }

    private function bestEffortAbort(
        MultipartUploadSessionData $session,
    ): void {
        try {
            $this->gateway->abort($session);
        } catch (Throwable $exception) {
            Log::warning('Multipart cleanup failed after verification rejection.', [
                'session_id' => $session->uploadId,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function assertActor(MediaMultipartUpload $session, MediaActorData $actor): void
    {
        if ((string) $session->uploader_id !== (string) $actor->id
            || $session->uploader_type !== $actor->type) {
            throw new MediaUploadException('Multipart session actor mismatch.');
        }
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('media.multipart.enabled', false)) {
            throw new MediaUploadException('Multipart uploads are disabled.');
        }
    }
}
