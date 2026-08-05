<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Services\MediaMultipartLock;
use Nvl\Media\Services\MediaMultipartSessionMapper;

/**
 * Idempotently aborts an actor-owned persisted multipart session.
 */
final readonly class AbortMultipartUploadAction
{
    public function __construct(
        private MultipartUploadGateway $gateway,
        private MediaMultipartLock $lock,
        private MediaMultipartSessionMapper $sessionMapper,
    ) {}

    public function execute(
        string $uploadId,
        MediaActorData $actor,
    ): void {
        $this->assertEnabled();

        $this->lock->execute($uploadId, function () use ($uploadId, $actor): void {
            $persisted = MediaMultipartUpload::query()->findOrFail($uploadId);
            $this->assertActor($persisted, $actor);

            if ($persisted->status === MediaMultipartStatus::Aborted) {
                return;
            }

            if ($persisted->status === MediaMultipartStatus::Completed) {
                throw new MediaUploadException('A completed multipart upload cannot be aborted.');
            }

            $this->gateway->abort($this->sessionMapper->toData($persisted));

            DB::transaction(function () use ($persisted): void {
                $locked = MediaMultipartUpload::query()
                    ->lockForUpdate()
                    ->findOrFail($persisted->id);

                if ($locked->status !== MediaMultipartStatus::Completed) {
                    $locked->forceFill([
                        'status' => MediaMultipartStatus::Aborted,
                        'provider_state' => null,
                        'failure_code' => null,
                        'failure_context' => null,
                    ])->save();
                }
            });
        });
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
