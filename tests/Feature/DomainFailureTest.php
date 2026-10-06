<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nvl\Media\Exceptions\MediaStorageException;
use Nvl\Media\Services\MediaLocalFileMaterializer;
use Nvl\Media\Services\MediaTemporaryFileRegistry;
use Nvl\Support\Http\PackageExceptionPayload;

it('classifies an unavailable remote media stream without exposing its source or creating a temporary file', function (): void {
    config()->set('filesystems.disks.c4-remote', ['driver' => 'c4-remote']);
    Storage::shouldReceive('disk')->once()->with('c4-remote')->andReturnSelf();
    Storage::shouldReceive('readStream')->once()->with('private-owner/credential.pdf')->andReturn(null);
    $temporaryFiles = app(MediaTemporaryFileRegistry::class);
    $before = $temporaryFiles->count();

    expect(fn () => app(MediaLocalFileMaterializer::class)->materialize('c4-remote', 'private-owner/credential.pdf'))
        ->toThrow(function (MediaStorageException $failure): void {
            $payload = app(PackageExceptionPayload::class)->for($failure);
            expect($failure)->toBeInstanceOf(RuntimeException::class)
                ->and($failure->responseCode())->toBe('storage_unavailable')->and($failure->suggestedStatus())->toBe(500)
                ->and($failure->publicContext())->toBe([])
                ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('private-owner', 'credential.pdf', 'c4-remote');
        });
    expect($temporaryFiles->count())->toBe($before);
});
