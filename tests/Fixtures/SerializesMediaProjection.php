<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;

/** Serializes the explicit public properties of Media's read-only test projections. */
trait SerializesMediaProjection
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_map(self::serializeProjectionValue(...), get_object_vars($this));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function serializeProjectionValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Arrayable => $value->toArray(),
            is_array($value) => array_map(self::serializeProjectionValue(...), $value),
            default => $value,
        };
    }
}
