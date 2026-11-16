<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Foundation\Application;

/**
 * Decides whether Media applies production rules, so uploads and the doctor agree.
 *
 * @internal
 */
final readonly class MediaEnvironment
{
    /**
     * Create the environment resolver.
     */
    public function __construct(
        private Application $app,
    ) {}

    /**
     * Use an explicit override when given, otherwise detect the application environment.
     */
    public function production(?bool $override = null): bool
    {
        return $override ?? $this->app->isProduction();
    }
}
