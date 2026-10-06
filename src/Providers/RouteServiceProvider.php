<?php

declare(strict_types=1);

namespace Nvl\Media\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;

/** Maps the optional management and asset-delivery routes. */
class RouteServiceProvider extends ServiceProvider
{
    use RegistersNamespacedResources;

    protected string $name = 'Media';

    /**
     * Bootstrap media route services.
     */
    public function boot(): void
    {
        $this->map();
    }

    /**
     * Map media API, asset, and web routes.
     */
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapAssetRoutes();
        $this->mapWebRoutes();
    }

    /**
     * Map media API routes behind the API middleware group.
     */
    protected function mapApiRoutes(): void
    {
        if (! (bool) config('nvl-media.routes.api_enabled', false)) {
            return;
        }

        Route::middleware($this->middleware('nvl-media.routes.api_middleware', ['api']))
            ->prefix(trim(MediaConfiguration::string('nvl-media.routes.api_prefix', 'nvl/api/v1'), '/'))
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
            });
    }

    /**
     * Map public and signed media asset routes outside the web middleware stack.
     */
    protected function mapAssetRoutes(): void
    {
        if (! (bool) config('nvl-media.routes.assets_enabled', true)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/assets.php');
    }

    /**
     * Map media web routes behind the web middleware group.
     */
    protected function mapWebRoutes(): void {}

    /**
     * Normalize configured route middleware.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    private function middleware(string $key, array $default): array
    {
        return array_values(array_filter(
            (array) config($key, $default),
            static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== '',
        ));
    }
}
