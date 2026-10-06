<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Models\Media;

/**
 * Delete a media handle through the public lifecycle workflow.
 *
 * @api
 */
interface DeleteMediaContract
{
    public function execute(Media|string $media, bool $force = false): bool;
}
