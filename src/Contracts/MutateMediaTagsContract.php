<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Models\Media;

/**
 * Mutates canonical media tags under the shared media lock.
 *
 * @api
 */
interface MutateMediaTagsContract
{
    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function execute(
        Media|string $media,
        array $add = [],
        array $remove = [],
    ): Media;
}
