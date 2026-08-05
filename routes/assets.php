<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Nvl\Media\Http\Controllers\MediaAssetController;

/** @var array<int, string> $publicAssetMiddlewares */
$publicAssetMiddlewares = array_values(array_filter(
    (array) config('media.assets.public_route_middleware', ['throttle:120,1']),
    static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== '',
));

/** @var array<int, string> $privateAssetMiddlewares */
$privateAssetMiddlewares = array_values(array_filter(
    (array) config('media.assets.private_route_middleware', []),
    static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== '',
));

$prefix = trim((string) config('media.routes.assets_prefix', 'media'), '/');

Route::prefix($prefix)->name('media.')->group(function () use ($publicAssetMiddlewares, $privateAssetMiddlewares): void {
    Route::get('/assets/{media}', [MediaAssetController::class, 'showPublic'])
        ->middleware(array_merge($publicAssetMiddlewares, [SubstituteBindings::class]))
        ->name('assets.show');

    Route::get('/private/{owner}/{media}', [MediaAssetController::class, 'showPrivate'])
        ->middleware(array_merge($privateAssetMiddlewares, ['signed', SubstituteBindings::class]))
        ->name('private.show');
});
