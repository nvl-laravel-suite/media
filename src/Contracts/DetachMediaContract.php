<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Models\Media;

interface DetachMediaContract
{
    public function execute(
        Media|string $media,
        Model $model,
        ?string $collection = null,
    ): int;
}
