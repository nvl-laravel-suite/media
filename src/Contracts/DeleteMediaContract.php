<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Models\Media;

interface DeleteMediaContract
{
    public function execute(Media|string $media, bool $force = false): bool;
}
