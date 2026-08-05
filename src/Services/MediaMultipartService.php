<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Actions\AbortMultipartUploadAction;
use Nvl\Media\Actions\CompleteMultipartUploadAction;
use Nvl\Media\Actions\InitiateMultipartUploadAction;
use Nvl\Media\Actions\SignMultipartPartAction;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\InitiateMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;
use Nvl\Media\Models\Media;

/**
 * Coordinates persisted multipart session actions without duplicating their invariants.
 */
final readonly class MediaMultipartService
{
    public function __construct(
        private InitiateMultipartUploadAction $initiate,
        private SignMultipartPartAction $signPart,
        private CompleteMultipartUploadAction $complete,
        private AbortMultipartUploadAction $abort,
    ) {}

    public function initiate(
        InitiateMultipartUploadData $data,
        MediaActorData $actor,
    ): MultipartUploadSessionData {
        return $this->initiate->execute($data, $actor);
    }

    public function signPart(
        SignMultipartPartData $part,
        MediaActorData $actor,
    ): SignedMultipartPartData {
        return $this->signPart->execute($part, $actor);
    }

    public function complete(
        CompleteMultipartUploadData $completion,
        MediaActorData $actor,
    ): Media {
        return $this->complete->execute($completion, $actor);
    }

    public function abort(string $uploadId, MediaActorData $actor): void
    {
        $this->abort->execute($uploadId, $actor);
    }
}
