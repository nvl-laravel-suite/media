<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

/**
 * Host-supplied enumeration boundary for platform-wide Media maintenance.
 *
 * @api
 */
interface MediaTenantWorklist
{
    /** @return list<string> */
    public function activeTenantIds(): array;
}
