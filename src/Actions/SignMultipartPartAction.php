<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Services\MediaMultipartSessionMapper;

/**
 * Signs a fixed-size checksummed part from persisted session state.
 */
final readonly class SignMultipartPartAction
{
    public function __construct(
        private MultipartUploadGateway $gateway,
        private MediaMultipartSessionMapper $sessionMapper,
    ) {}

    public function execute(
        SignMultipartPartData $part,
        MediaActorData $actor,
    ): SignedMultipartPartData {
        $this->assertEnabled();
        $expired = false;
        $session = DB::transaction(function () use ($part, $actor, &$expired): MediaMultipartUpload {
            $session = MediaMultipartUpload::query()
                ->lockForUpdate()
                ->findOrFail($part->uploadId);
            $this->assertActor($session, $actor);

            if ($session->status !== MediaMultipartStatus::Initiated) {
                throw new MediaUploadException(
                    "Multipart session is not active; status is [{$session->status->value}].",
                );
            }

            if ($session->expires_at->isPast()) {
                $session->status = MediaMultipartStatus::Expired;
                $session->save();
                $expired = true;

                return $session;
            }

            if ($part->partNumber < 1 || $part->partNumber > $session->expected_parts) {
                throw new MediaUploadException('Multipart part number is outside the session bounds.');
            }

            if ($part->byteLength !== $this->expectedLength($session, $part->partNumber)) {
                throw new MediaUploadException('Multipart part byte length does not match its session bounds.');
            }

            if (preg_match('/^[a-f0-9]{64}$/', $part->checksum) !== 1) {
                throw new MediaUploadException('Multipart parts require a lowercase SHA-256 checksum.');
            }

            $signedParts = is_array($session->signed_parts) ? $session->signed_parts : [];
            $existing = $signedParts[$part->partNumber] ?? null;
            $expected = ['length' => $part->byteLength, 'checksum' => $part->checksum];

            if ($existing !== null && $existing !== $expected) {
                throw new MediaUploadException(
                    "Multipart part [{$part->partNumber}] was already signed with different metadata.",
                );
            }

            $signedParts[$part->partNumber] = $expected;
            $session->signed_parts = $signedParts;
            $session->save();

            return $session;
        });

        if ($expired) {
            throw new MediaUploadException('Multipart upload session has expired.');
        }

        return $this->gateway->signPart(
            $this->sessionMapper->toData($session),
            new SignMultipartPartData(
                uploadId: $session->id,
                partNumber: $part->partNumber,
                checksum: $part->checksum,
                byteLength: $part->byteLength,
            ),
        );
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('media.multipart.enabled', false)) {
            throw new MediaUploadException('Multipart uploads are disabled.');
        }
    }

    private function expectedLength(MediaMultipartUpload $session, int $partNumber): int
    {
        if ($partNumber < $session->expected_parts) {
            return $session->part_size;
        }

        return $session->expected_size - ($session->part_size * ($session->expected_parts - 1));
    }

    private function assertActor(MediaMultipartUpload $session, MediaActorData $actor): void
    {
        if ((string) $session->uploader_id !== (string) $actor->id
            || $session->uploader_type !== $actor->type) {
            throw new MediaUploadException('Multipart session actor mismatch.');
        }
    }
}
