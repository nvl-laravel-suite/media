<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Consumer\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaMultipart;
use Nvl\Support\Enums\SystemActor;

/**
 * Coordinates persisted multipart session actions without duplicating their invariants.
 */
final readonly class MediaMultipartService
{
    public function __construct(
        private MediaMultipart $initiate,
        private MediaMultipart $signPart,
        private MediaMultipart $complete,
        private MediaMultipart $abort,
    ) {}

    public function initiate(
        array $data,
        Authenticatable|SystemActor $actor,
    ): array {
        return $this->initiate->initiate($data, $actor);
    }

    public function signPart(
        array $part,
        Authenticatable|SystemActor $actor,
    ): array {
        return $this->signPart->signPart($part, $actor);
    }

    public function complete(
        array $completion,
        Authenticatable|SystemActor $actor,
    ): Media {
        return $this->complete->complete($completion, $actor);
    }

    public function abort(string $uploadId, Authenticatable|SystemActor $actor): void
    {
        $this->abort->abort($uploadId, $actor);
    }
}
