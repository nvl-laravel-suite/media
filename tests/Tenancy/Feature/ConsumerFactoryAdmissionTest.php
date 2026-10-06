<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Media\Tests\MediaTenancyTestCase;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

uses(MediaTenancyTestCase::class);

it('admits an exact persisted owner asset graph and rejects cross-tenant factory handles', function (): void {
    $scenario = MediaTenancyScenario::install();
    $ownerA = $scenario->owner($scenario::A);
    $ownerB = $scenario->owner($scenario::B);
    $assetA = $scenario->run($scenario::A, fn () => Media::factory()->create(['disk' => 'tenant-disk']));
    expect($assetA->storage_path)->toBe('media/tenants/'.$scenario::A.'/factory/media/'.$assetA->hash);
    $association = $scenario->run($scenario::A, fn () => MediaAssociation::factory()->forOwner($ownerA)->forMedia($assetA)->create());
    expect($association->tenant_id)->toBe($scenario::A)
        ->and($association->media_id)->toBe($assetA->id)
        ->and($association->associable_id)->toBe($ownerA->id)
        ->and($association->associable_type)->toBe($ownerA->getMorphClass());
    expect(fn () => $scenario->run($scenario::B, fn () => MediaAssociation::factory()->forOwner($ownerB)->forMedia($assetA)->make()))->toThrow(TenantBoundaryViolation::class);
    expect(fn () => $scenario->run($scenario::A, fn () => MediaAssociation::factory()->forOwner($ownerB)->forMedia($assetA)->make()))->toThrow(TenantBoundaryViolation::class);
    expect($scenario->run($scenario::A, fn () => MediaAssociation::query()->count()))->toBe(1)
        ->and($scenario->run($scenario::B, fn () => MediaAssociation::query()->count()))->toBe(0)
        ->and(Storage::disk('tenant-disk')->allFiles())->toBe([]);
});

it('keeps detached handles query free and preserves an explicit canonical fixture path after admission', function (): void {
    $scenario = MediaTenancyScenario::install();
    $queries = [];
    $recording = false;
    DB::listen(function ($query) use (&$queries, &$recording): void {
        if ($recording) {
            $queries[] = $query->sql;
        }
    });
    $scenario->run($scenario::A, function () use ($scenario, &$recording, &$queries): void {
        $recording = true;
        $detached = Media::factory()->withoutParents()->make();
        $recording = false;
        expect($queries)->toBe([])->and($detached->exists)->toBeFalse()
            ->and(array_key_exists('storage_path', $detached->getAttributes()))->toBeFalse();
        $path = 'media/tenants/'.$scenario::A.'/custom/explicit.jpg';
        $created = Media::factory()->create(['disk' => 'tenant-disk', 'storage_path' => $path, 'tenant_id' => $scenario::B]);
        expect($created->storage_path)->toBe($path)->and($created->tenant_id)->toBe($scenario::A);
    });
    expect(Storage::disk('tenant-disk')->allFiles())->toBe([]);
});
