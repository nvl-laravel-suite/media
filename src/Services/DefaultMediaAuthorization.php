<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Support\MediaConfiguration;

/**
 * Conservative standalone policy based on public visibility and uploader identity.
 */
final class DefaultMediaAuthorization implements MediaAuthorization
{
    /**
     * Apply standalone media authorization.
     */
    public function allows(
        MediaActorData $actor,
        MediaAbility $ability,
        ?Media $media = null,
        ?Model $owner = null,
    ): bool {
        if ($actor->system) {
            return true;
        }

        if ($ability === MediaAbility::List || $ability === MediaAbility::Upload) {
            return $actor->id !== null;
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

        if ($actor->signed && $ability === MediaAbility::Download) {
            $expectedOwner = $media->uploaded_by
                ?? MediaConfiguration::string('media.assets.private_owner_fallback', 'system');

            return hash_equals((string) $expectedOwner, (string) $actor->id);
        }

        if ((string) $media->uploaded_by !== (string) $actor->id) {
            return false;
        }

        if (! is_string($media->uploaded_by_type)
            || $media->uploaded_by_type === ''
            || ! is_string($actor->type)
            || $actor->type === '') {
            return false;
        }

        return hash_equals($media->uploaded_by_type, $actor->type);
    }
}
