<?php

declare(strict_types=1);

namespace Nvl\Media\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Nvl\Media\Support\MediaConfiguration;

/** Maps the optional management and asset-delivery routes. */
class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Media';

    /**
     * Bootstrap media route services.
     */
    public function boot(): void
    {
        parent::boot();
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
        if (! (bool) config('media.routes.api_enabled', false)) {
            return;
        }

        Route::middleware($this->middleware('media.routes.api_middleware', ['api']))
            ->prefix(trim(MediaConfiguration::string('media.routes.api_prefix', 'api/v1'), '/'))
            ->group(__DIR__.'/../../routes/api.php');
    }

    /**
     * Map public and signed media asset routes outside the web middleware stack.
     */
    protected function mapAssetRoutes(): void
    {
        if (! (bool) config('media.routes.assets_enabled', true)) {
            return;
        }

        Route::group([], __DIR__.'/../../routes/assets.php');
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
