<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Media\Http\Controllers\Api\MediaAssociationController;
use Nvl\Media\Http\Controllers\Api\MediaLibraryController;
use Nvl\Media\Http\Controllers\Api\MediaMutationController;
use Nvl\Media\Http\Controllers\Api\MediaUploadController;
use Nvl\Media\Http\Controllers\Api\MediaVariationController;

/** @var list<string> $managementMiddleware */
$managementMiddleware = array_values(array_filter(
    (array) config('media.routes.management_middleware', ['auth', 'throttle:60,1']),
    static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== '',
));

Route::prefix('media')
    ->name('nvl.media.management.')
    ->middleware($managementMiddleware)
    ->group(function (): void {
        Route::get('/', [MediaLibraryController::class, 'index'])->name('index');
        Route::post('/', [MediaUploadController::class, 'store'])->name('store');
        Route::post('/reorder', [MediaMutationController::class, 'reorder'])->name('reorder');
        Route::post('/bulk', [MediaMutationController::class, 'bulk'])->name('bulk');

        Route::prefix('{media}')->group(function (): void {
            Route::get('/', [MediaLibraryController::class, 'show'])->name('show');
            Route::put('/', [MediaMutationController::class, 'update'])->name('update');
            Route::patch('/', [MediaMutationController::class, 'update'])->name('update.patch');
            Route::delete('/', [MediaMutationController::class, 'destroy'])->name('destroy');
            Route::post('/attach', [MediaAssociationController::class, 'attach'])->name('attach');
            Route::post('/detach', [MediaAssociationController::class, 'detach'])->name('detach');
            Route::get('/variations', [MediaVariationController::class, 'variations'])->name('variations');
            Route::post('/regenerate', [MediaVariationController::class, 'regenerate'])->name('regenerate');
            Route::patch('/rename', [MediaMutationController::class, 'rename'])->name('rename');
            Route::get('/usages', [MediaLibraryController::class, 'usages'])->name('usages');
            Route::get('/download', [MediaLibraryController::class, 'download'])->name('download');
            Route::post('/replace', [MediaUploadController::class, 'replace'])->name('replace');
        });
    });
