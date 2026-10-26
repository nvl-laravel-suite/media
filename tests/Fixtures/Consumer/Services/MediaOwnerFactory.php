<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Consumer\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Tests\Fixtures\Consumer\MediaOwner;

/** Constructs each owner API around the actual Eloquent owner and injected collaborators. */
final readonly class MediaOwnerFactory
{
    /** Defer runtime collaborators until the owner performs an operation.
     * @param  Closure(): MediaModelInteractionService  $interactions
     * @param  Closure(): MediaLifecycleService  $lifecycle
     */
    public function __construct(private Closure $interactions, private Closure $lifecycle) {}

    /** Create an independent definition registry for one model instance. */
    public function for(Model&HasNvlMedia $owner): MediaOwner
    {
        return new MediaOwner($owner, $this->interactions, $this->lifecycle, new MediaRegistration);
    }
}
