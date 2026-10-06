<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use InvalidArgumentException;
use LogicException;
use Nvl\Media\Data\MediaAdoptionResultData;

/**
 * Plans or applies a reconciled Spatie-style media adoption.
 *
 * @api
 */
interface AdoptSpatieMediaContract
{
    /**
     * Plan or apply a non-destructive import from staged legacy tables.
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function execute(
        string $sourceTable,
        ?string $translationTable = null,
        ?string $variationTable = null,
        ?string $uploaderType = null,
        string $defaultLocale = 'en',
        bool $apply = false,
    ): MediaAdoptionResultData;
}
