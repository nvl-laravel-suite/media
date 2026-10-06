<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nvl\Media\Models\Media;

it('creates ordinary disabled-tenancy fixtures without adopted columns or backing files', function (): void {
    Storage::fake('public');
    expect(config('nvl-tenancy.enabled'))->not->toBeTrue();
    $media = Media::factory()->create(['disk' => 'public']);
    expect($media->exists)->toBeTrue()
        ->and(array_key_exists('storage_path', $media->getAttributes()))->toBeFalse()
        ->and($media->fresh()->buildPath())->toBe('media/factory/media/'.$media->hash)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});
