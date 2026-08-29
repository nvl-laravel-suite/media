<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

use Illuminate\Contracts\Cache\Store;

/** Cache store stub that intentionally omits atomic locking support. */
final class NonLockingMediaDoctorStore implements Store
{
    public function get(mixed $key): mixed
    {
        return null;
    }

    public function many(array $keys): array
    {
        return array_fill_keys($keys, null);
    }

    public function put(mixed $key, mixed $value, mixed $seconds): bool
    {
        return true;
    }

    public function putMany(array $values, mixed $seconds): bool
    {
        return true;
    }

    public function increment(mixed $key, mixed $value = 1): int|bool
    {
        return 1;
    }

    public function decrement(mixed $key, mixed $value = 1): int|bool
    {
        return 0;
    }

    public function forever(mixed $key, mixed $value): bool
    {
        return true;
    }

    public function touch(mixed $key, mixed $seconds): bool
    {
        return true;
    }

    public function forget(mixed $key): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
