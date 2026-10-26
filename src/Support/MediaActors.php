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
    /** Return the current persisted principal after admitting its canonical identity. */
    public static function assert(Authenticatable|SystemActor $actor): Authenticatable|SystemActor
    {
        if ($actor instanceof SystemActor) {
            return $actor;
        }
        if (! $actor instanceof Model) {
            throw new AuthorizationException('Media requires a persisted actor or an explicit system actor.');
        }
        $prototype = new ($actor::class);
        $key = $actor->getKey();
        if (! $actor->exists || (! is_int($key) && ! is_string($key)) || $key === ''
            || $actor->getRawOriginal($prototype->getKeyName()) !== $key
            || $actor->getTable() !== $prototype->getTable()
            || $actor->getKeyName() !== $prototype->getKeyName()
            || ($actor->getConnectionName() !== null && $actor->getConnectionName() !== $prototype->getConnection()->getName())
            || $actor->getConnection() !== $prototype->getConnection()) {
            throw new AuthorizationException('Media requires a persisted actor with canonical identity or an explicit system actor.');
        }
        $current = $prototype->newQuery()->useWritePdo()->whereKey($key)->first();
        if (! $current instanceof Authenticatable) {
            throw new AuthorizationException('The Media actor is no longer available.');
        }
        self::id($current);

        return $current;
    }

    public static function id(Authenticatable|SystemActor $actor): ?string
    {
        if ($actor instanceof SystemActor) {
            return null;
        }
        $identifier = $actor->getAuthIdentifier();
        if ((! is_int($identifier) && ! is_string($identifier)) || (string) $identifier === '') {
            throw new AuthorizationException('The Media actor identifier is invalid.');
        }

        return (string) $identifier;
    }

    public static function type(Authenticatable|SystemActor $actor): string
    {
        return $actor instanceof SystemActor ? 'system' : ($actor instanceof Model ? $actor->getMorphClass() : $actor::class);
    }
}
