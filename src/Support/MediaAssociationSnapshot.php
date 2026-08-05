<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Nvl\Media\Models\MediaAssociation;

/**
 * Builds stable event payloads for Media association ownership metadata.
 */
final class MediaAssociationSnapshot
{
    /**
     * Build a snapshot payload from one media association.
     *
     * @param  MediaAssociation  $association  Media association model
     * @return array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string|null}
     */
    public static function fromAssociation(MediaAssociation $association): array
    {
        return [
            'media_id' => $association->media_id,
            'associable_type' => $association->associable_type,
            'associable_id' => $association->associable_id,
            'collection' => $association->collection,
            'locale' => $association->locale,
        ];
    }

    /**
     * Build snapshot payloads from media associations.
     *
     * @param  iterable<int, MediaAssociation>  $associations  Media association models
     * @return array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: string|null}>
     */
    public static function fromAssociations(iterable $associations): array
    {
        $snapshots = [];

        foreach ($associations as $association) {
            $snapshots[] = self::fromAssociation($association);
        }

        return $snapshots;
    }
}
