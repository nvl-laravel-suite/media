<?php

declare(strict_types=1);

namespace Nvl\Media\Data\Display;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Safe original-asset display fields without private storage or Download capabilities.
 *
 * @api
 */
#[TypeScript]
final class OwnerMediaSlotItemData extends Data
{
    /** Retain the approved original-media projection. */
    public function __construct(
        public readonly string $associationId,
        public readonly string $mediaId,
        public readonly ?string $title,
        public readonly ?string $altText,
        public readonly ?string $caption,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly bool $isPublic,
        public readonly ?string $url,
    ) {}
}
