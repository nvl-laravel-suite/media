<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Support\MediaActors;
use Nvl\Support\Enums\SystemActor;

/**
 * Conservative standalone policy based on public visibility and uploader identity.
 *
 * It never grants FinalizeScan: an uploader must not approve its own upload, so hosts grant it to their scanner.
 *
 * @internal
 */
final class DefaultMediaAuthorization implements MediaAuthorization
{
    /**
     * Apply standalone media authorization.
     */
    public function allows(
        Authenticatable|SystemActor $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool {
        if ($actor instanceof SystemActor || $ability === MediaAbility::FinalizeScan) {
            return false;
        }

        if ($ability === MediaAbility::List || $ability === MediaAbility::Upload) {
            return MediaActors::id($actor) !== null;
        }

        if (! $media instanceof Media) {
            return false;
        }

        if ($media->is_public && in_array($ability, [
            MediaAbility::View,
            MediaAbility::Download,
            MediaAbility::Reuse,
        ], true)) {
            return true;
        }

        if ((string) $media->uploaded_by !== (string) MediaActors::id($actor)) {
            return false;
        }

        if (! is_string($media->uploaded_by_type)
            || $media->uploaded_by_type === ''
            || MediaActors::type($actor) === '') {
            return false;
        }

        return hash_equals($media->uploaded_by_type, MediaActors::type($actor));
    }
}
