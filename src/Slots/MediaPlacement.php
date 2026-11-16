<?php

declare(strict_types=1);

namespace Nvl\Media\Slots;

use InvalidArgumentException;

/**
 * Placement of one asset on an owner, independent of its storage location.
 *
 * @api
 */
final readonly class MediaPlacement
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $collection,
        public ?MediaSlot $slot = null,
        public ?string $locale = null,
        public ?int $order = null,
        public array $metadata = [],
    ) {
        if ($slot !== null && (trim($slot->name) === '' || mb_strlen($slot->name) > 255)) {
            throw new InvalidArgumentException('Media placement requires a valid bounded slot name.');
        }
        if (array_key_exists('slot', $metadata) && $metadata['slot'] !== $slot?->name) {
            throw new InvalidArgumentException('Media placement metadata must agree with its selected slot.');
        }
    }
}
