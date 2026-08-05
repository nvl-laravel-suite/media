<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvl\Media\Models\Media;

/**
 * Announces that a new media row and its physical object were created successfully.
 */
final class MediaUploadedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * Create the committed media upload event.
     */
    public function __construct(public Media $media) {}
}
