<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;

it('deduplicates within A but never reuses A asset or object in B', function (): void {
    $scenario = MediaTenancyScenario::install();
    $assetA1 = $scenario->upload($scenario::A, 'identical tenant bytes');
    $assetA2 = $scenario->upload($scenario::A, 'identical tenant bytes');
    $assetB = $scenario->upload($scenario::B, 'identical tenant bytes');

    $pathA = $scenario->run($scenario::A, fn (): string => $assetA1->buildPath());
    $pathB = $scenario->run($scenario::B, fn (): string => $assetB->buildPath());

    expect($assetA2->id)->toBe($assetA1->id)
        ->and($assetB->id)->not->toBe($assetA1->id)
        ->and($pathB)->not->toBe($pathA)
        ->and($pathA)->toContain('/tenants/'.$scenario::A.'/')
        ->and($pathB)->toContain('/tenants/'.$scenario::B.'/');
});

it('cleans only the active tenant orphan prefix', function (): void {
    $scenario = MediaTenancyScenario::install();
    $mediaB = $scenario->upload($scenario::B, 'tenant B live bytes');
    $pathB = $scenario->run($scenario::B, fn (): string => $mediaB->buildPath());
    $orphanA = 'media/tenants/'.$scenario::A.'/orphans/old.txt';
    Storage::disk('tenant-disk')->put($orphanA, 'orphan A');

    $exitCode = $scenario->run($scenario::A, fn (): int => $this->artisan('nvl:media:reconcile', [
        '--disk' => 'tenant-disk',
        '--orphans' => true,
        '--cleanup-orphans' => true,
        '--older-than' => 0,
    ])->run());

    expect($exitCode)->toBe(0)
        ->and(Storage::disk('tenant-disk')->exists($orphanA))->toBeFalse()
        ->and(Storage::disk('tenant-disk')->exists($pathB))->toBeTrue();
});

it('round trips a multipart physical path through one logical tenant folder', function (): void {
    $scenario = MediaTenancyScenario::install();

    $folder = $scenario->run($scenario::A, static function () use ($scenario): string {
        $paths = app(MediaPathResolver::class);
        $logical = $paths->logicalFolderFromStoragePath(
            'media/tenants/'.$scenario::A.'/multipart/session/object.txt',
        );

        return $paths->storageFolder($logical);
    });

    expect($folder)->toBe('media/tenants/'.$scenario::A.'/multipart/session')
        ->not->toContain('/tenants/'.$scenario::A.'/tenants/');
});
