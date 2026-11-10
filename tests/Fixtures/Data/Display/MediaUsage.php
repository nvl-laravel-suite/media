<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Data\Display;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Tests\Fixtures\SerializesMediaProjection;

/**
 * MediaUsage: read-only DTO representing a media association usage row.
 *
 * @api
 */
final class MediaUsage implements Arrayable, JsonSerializable
{
    use SerializesMediaProjection;

    public function __construct(
        /** @var string */
        public readonly string $id,

        /** @var string */
        public readonly string $type,

        /** @var string */
        public readonly string $modelId,

        /** @var string|null */
        public readonly ?string $collection,

        /** @var string|null */
        public readonly ?string $locale,

        /** @var int|null */
        public readonly ?int $order,
    ) {}

    /**
     * Create DTO from Eloquent model.
     *
     * @internal
     */
    public static function fromModel(MediaAssociation $association): self
    {
        return new self(
            id: $association->id,
            type: $association->associable_type,
            modelId: $association->associable_id,
            collection: $association->collection,
            locale: $association->locale,
            order: $association->order,
        );
    }
}
