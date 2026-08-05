<?php

declare(strict_types=1);

namespace Nvl\Media\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable media actor identity for policies and events.
 */
#[TypeScript]
final class MediaActorData extends Data
{
    /**
     * Create a media actor identity.
     */
    public function __construct(
        public readonly ?string $type,
        public readonly int|string|null $id,
        public readonly bool $system = false,
        public readonly bool $signed = false,
    ) {}

    /**
     * Create an actor from Laravel authentication.
     */
    public static function fromAuthenticatable(Authenticatable $actor): self
    {
        $identifier = $actor->getAuthIdentifier();

        return new self(
            type: $actor instanceof Model ? $actor->getMorphClass() : $actor::class,
            id: is_int($identifier) || is_string($identifier) ? $identifier : null,
        );
    }

    /**
     * Create a trusted internal actor.
     */
    public static function system(): self
    {
        return new self('system', null, system: true);
    }

    /**
     * Create the capability identity carried by a valid signed URL.
     */
    public static function signed(string $owner): self
    {
        return new self(null, $owner, signed: true);
    }
}
