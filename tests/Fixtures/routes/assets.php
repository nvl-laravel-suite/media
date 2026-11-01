<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Nvl\Media\Tests\Fixtures\Http\Controllers\MediaAssetController;

/** @var array<int, string> $publicAssetMiddlewares */
$publicAssetMiddlewares = array_values(array_filter(
    (array) config('nvl-media.assets.public_route_middleware', ['throttle:120,1']),
    static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== '',
));

/** @var array<int, string> $privateAssetMiddlewares */
$privateAssetMiddlewares = array_values(array_filter(
    (array) config('nvl-media.assets.private_route_middleware', []),
    static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== '',
));

$prefix = trim((string) config('nvl-media.routes.assets_prefix', 'nvl/media'), '/');

Route::prefix($prefix)->name('nvl.media.')->group(function () use ($publicAssetMiddlewares, $privateAssetMiddlewares): void {
    Route::get('/assets/{media}', [MediaAssetController::class, 'showPublic'])
        ->middleware(array_merge($publicAssetMiddlewares, [SubstituteBindings::class]))
        ->name('assets.show');

    Route::get('/authenticated/{media}', [MediaAssetController::class, 'showAuthenticated'])
        ->middleware(['auth', SubstituteBindings::class])
        ->name('authenticated.show');

    Route::get('/private/{owner}/{media}', [MediaAssetController::class, 'showPrivate'])
        ->middleware(array_merge($privateAssetMiddlewares, ['signed', SubstituteBindings::class]))
        ->name('private.show');
});
