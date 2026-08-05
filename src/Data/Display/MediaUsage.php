<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Models\MediaAssociation;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** MediaUsage: read-only DTO representing a media association usage row. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MediaUsage extends Data
{
    use DataTransform;

    public function __construct(
        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $id,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $type,

        /** @var string */
        #[LiteralTypeScriptType('string')]
        public readonly string $modelId,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $collection,

        /** @var string|null */
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $locale,

        /** @var int|null */
        #[LiteralTypeScriptType('number | null')]
        public readonly ?int $order,
    ) {}

    /**
     * Create DTO from Eloquent model.
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
