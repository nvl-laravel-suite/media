<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

/**
 * Resolves every IP address advertised for a remote media hostname.
 */
interface MediaHostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
