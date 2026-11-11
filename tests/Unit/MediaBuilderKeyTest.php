<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaModelRegistry;

it('treats a malformed media identifier as absent without sending it to the database', function (): void {
    $media = Media::factory()->create();
    DB::connection($media->getConnectionName())->enableQueryLog();

    expect(MediaModelRegistry::query()->find('missing-media'))->toBeNull()
        ->and(fn () => MediaModelRegistry::query()->findOrFail('missing-media'))->toThrow(ModelNotFoundException::class)
        ->and(MediaModelRegistry::query()->whereKey($media->id)->exists())->toBeTrue();

    $bindings = array_merge(...array_column(DB::connection($media->getConnectionName())->getQueryLog(), 'bindings'));

    expect($bindings)->not->toContain('missing-media');
});
