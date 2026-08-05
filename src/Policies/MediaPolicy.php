<?php

declare(strict_types=1);

namespace Nvl\Media\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAccessService;

/**
 * Adapts Laravel policy abilities to the consumer-replaceable media contract.
 */
final readonly class MediaPolicy
{
    /**
     * Create the policy adapter.
     */
    public function __construct(
        private MediaAccessService $access,
    ) {}

    public function viewAny(Authenticatable $actor): bool
    {
        return $this->allows($actor, MediaAbility::List);
    }

    public function view(Authenticatable $actor, Media $media): bool
    {
        return $this->allows($actor, MediaAbility::View, $media);
    }

    public function create(Authenticatable $actor): bool
    {
        return $this->allows($actor, MediaAbility::Upload);
    }

    public function update(Authenticatable $actor, Media $media): bool
    {
        return $this->allows($actor, MediaAbility::Mutate, $media);
    }

    public function delete(Authenticatable $actor, Media $media): bool
    {
        return $this->allows($actor, MediaAbility::Delete, $media);
    }

    public function attach(Authenticatable $actor, Media $media): bool
    {
        return $this->allows($actor, MediaAbility::Associate, $media);
    }

    public function detach(Authenticatable $actor, Media $media): bool
    {
        return $this->allows($actor, MediaAbility::Associate, $media);
    }

    public function regenerate(Authenticatable $actor, Media $media): bool
    {
        return $this->allows($actor, MediaAbility::Mutate, $media);
    }

    public function download(Authenticatable $actor, Media $media): bool
    {
        return $this->allows($actor, MediaAbility::Download, $media);
    }

    /**
     * Resolve a stable actor and evaluate the authorization contract.
     */
    private function allows(
        Authenticatable $actor,
        MediaAbility $ability,
        ?Media $media = null,
    ): bool {
        return $this->access->allows($actor, $ability, $media);
    }
}
