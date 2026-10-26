<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Enums\SystemActor;

/** Admits explicit persisted actors and derives trusted storage identities. */
final class MediaActors
{
    public static function assert(Authenticatable|SystemActor $actor): void
    {
        if ($actor instanceof SystemActor) {
            return;
        }
        if (! $actor instanceof Model || ! $actor->exists || $actor->getKey() === null
            || $actor->getRawOriginal($actor->getKeyName()) === null
            || (string) $actor->getRawOriginal($actor->getKeyName()) !== (string) $actor->getKey()
            || ! $actor->newQuery()->whereKey($actor->getKey())->exists()) {
            throw new AuthorizationException('Media requires a persisted actor or an explicit system actor.');
        }
    }

    public static function id(Authenticatable|SystemActor $actor): ?string
    {
        return $actor instanceof SystemActor ? null : (string) $actor->getAuthIdentifier();
    }

    public static function type(Authenticatable|SystemActor $actor): string
    {
        return $actor instanceof SystemActor ? 'system' : ($actor instanceof Model ? $actor->getMorphClass() : $actor::class);
    }
}
