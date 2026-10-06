<?php

declare(strict_types=1);

namespace Nvl\Media;

use Illuminate\Support\Collection;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Slots\MediaSlot;

/**
 * Retains slot and conversion definitions for one NVL owner API.
 *
 * @api
 */
final class MediaRegistration
{
    /** @var array<string, MediaSlot> */
    private array $slots = [];

    /** @var array<string, ConversionDefinition> */
    private array $conversions = [];

    /** Define or replace one allowed slot. */
    public function slot(string $name): MediaSlot
    {
        return $this->slots[$name] = new MediaSlot($name);
    }

    /** Define or replace one owner conversion. */
    public function conversion(string $name): ConversionDefinition
    {
        return $this->conversions[$name] = new ConversionDefinition($name);
    }

    /** Find a registered slot without making an implicit definition. */
    public function getSlot(string $name): ?MediaSlot
    {
        return $this->slots[$name] ?? null;
    }

    /** Return registered slots.
     * @return Collection<string, MediaSlot>
     */
    public function slots(): Collection
    {
        return collect($this->slots);
    }

    /** Return registered owner conversions.
     * @return array<string, ConversionDefinition>
     */
    public function conversions(): array
    {
        return $this->conversions;
    }
}
