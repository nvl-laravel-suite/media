<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;

function createRegenerableMedia(array $overrides = []): Media
{
    return Media::create(array_merge([
        'filename' => 'regenerate-test.jpg',
        'hash' => md5(uniqid('', true)).'.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'disk' => 'public',
        'folder' => 'media/regenerate',
        'is_public' => true,
        'type' => MediaType::IMAGE,
        'digest' => md5(uniqid('regenerate-test', true)),
    ], $overrides));
}

it('registers the regenerate variations command', function (): void {
    expect(Artisan::all())->toHaveKey('nvl:media:regenerate');
});

it('reports zero matching records in dry-run mode', function (): void {
    $this->artisan('nvl:media:regenerate', [
        '--dry-run' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutput('Found 0 media records matching filters.')
        ->expectsOutput('Presets: all enabled presets')
        ->expectsOutput('No media records to process.')
        ->assertExitCode(0);
});

it('counts filtered image records in dry-run mode without mutating media', function (): void {
    $image = createRegenerableMedia(['filename' => 'regenerate-image.jpg']);
    $document = createRegenerableMedia([
        'filename' => 'regenerate-document.pdf',
        'hash' => md5(uniqid('', true)).'.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::DOCUMENT,
    ]);

    $this->artisan('nvl:media:regenerate', [
        '--type' => 'image',
        '--dry-run' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutput('Found 1 media records matching filters.')
        ->expectsOutput('[dry-run] Would regenerate variations for 1 media records.')
        ->assertExitCode(0);

    expect($image->fresh())->not->toBeNull()
        ->and($document->fresh())->not->toBeNull();
});

it('excludes media that has not passed the scanner boundary', function (): void {
    createRegenerableMedia([
        'status' => MediaLifecycleStatus::Quarantined,
        'available_at' => null,
    ]);

    $this->artisan('nvl:media:regenerate', [
        '--dry-run' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutput('Found 0 media records matching filters.')
        ->expectsOutput('No media records to process.')
        ->assertExitCode(0);
});
