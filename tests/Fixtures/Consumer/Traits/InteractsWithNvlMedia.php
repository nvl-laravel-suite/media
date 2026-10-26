<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Consumer\Traits;

use Nvl\Media\Models\Media;
use Nvl\Media\Tests\Fixtures\Consumer\MediaOwner;
use Nvl\Media\Tests\Fixtures\Consumer\Services\MediaOwnerFactory;

/**
 * Adds only namespaced NVL entry points and lifecycle state to an Eloquent owner.
 *
 * @api
 *
 * @nvl-consumer-relation nvlMediaAssociations
 */
trait InteractsWithNvlMedia
{
    use \Nvl\Media\Traits\InteractsWithNvlMedia;

    private ?MediaOwner $nvlMediaOwner = null;

    /**
     * Boot NVL deletion independently of any other media implementation.
     *
     * @internal
     */
    public static function bootInteractsWithNvlMedia(): void
    {
        static::deleted(static function (self $owner): void {
            $owner->nvlMedia()->ownerDeleted();
        });
    }

    /** Resolve the owner-scoped API from Laravel's container at the model boundary. */
    public function nvlMedia(): MediaOwner
    {
        if ($this->nvlMediaOwner === null || ! $this->nvlMediaOwner->owns($this)) {
            $this->nvlMediaOwner = app(MediaOwnerFactory::class)->for($this);
        }

        return $this->nvlMediaOwner;
    }
}
