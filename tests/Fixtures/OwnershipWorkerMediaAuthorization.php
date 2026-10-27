<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Models\Media;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/** Grants only the current fixture variation operation under native queue admission. */
final class OwnershipWorkerMediaAuthorization implements MediaAuthorization
{
    private ?GenerateImageVariationJob $job = null;

    /** @param Closure(): void $operation */
    public function generate(GenerateImageVariationJob $job, Closure $operation): void
    {
        $previous = $this->job;
        $this->job = $job;
        try {
            $operation();
        } finally {
            $this->job = $previous;
        }
    }

    public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
    {
        if ($actor !== SystemActor::Background || $ability !== MediaAbility::Mutate
            || $owner !== null || $media === null || $this->job === null
            || $media->id !== $this->job->mediaId || $media->revision !== $this->job->sourceRevision) {
            return false;
        }
        app(OwnershipBoundary::class)->assertRecord($media, 'media.assets');

        return true;
    }
}
