<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Counts constructions per concrete class for owner-identity resolution tests. */
abstract class CountedIdentityModel extends Model
{
    /** @var array<class-string, int> */
    public static array $constructed = [];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        self::$constructed[static::class] = (self::$constructed[static::class] ?? 0) + 1;
        parent::__construct($attributes);
    }
}
