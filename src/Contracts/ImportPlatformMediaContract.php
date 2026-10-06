<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Data\Mutations\ImportPlatformMediaData;
use Nvl\Media\Models\Media;

/**
 * Standalone facade around Media's staged catalog transaction port.
 *
 * @api
 */
interface ImportPlatformMediaContract
{
    /** Execute the import platform media workflow. */
    public function execute(ImportPlatformMediaData $data): Media;
}
