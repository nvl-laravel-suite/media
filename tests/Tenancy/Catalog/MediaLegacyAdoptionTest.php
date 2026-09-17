<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

it('splits one legacy asset shared across tenants into verified independent graphs', function (): void {
    Storage::fake('tenant-disk');
    $coordinator = app(TenantAdoptionCoordinator::class);
    $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
    $ownerPlan = $coordinator->prepare(['resource-fixture-owners'], [], $operation);

    expect($coordinator->backfill($ownerPlan, 100, $operation))->toBeTrue();
    $coordinator->activate($ownerPlan, $operation);

    $ownerA = (string) Str::uuid();
    $ownerB = (string) Str::uuid();
    $sourceId = (string) Str::uuid();
    $destinationId = (string) Str::uuid();
    $hash = hash('sha256', 'legacy-name').'.jpg';
    $bytes = 'legacy-shared-original';
    $digest = hash('sha256', $bytes);
    $sourcePath = 'media/legacy/'.$hash;
    $variationPath = 'media/legacy/conversions/'.pathinfo($hash, PATHINFO_FILENAME).'-thumb.webp';
    $now = now();

    DB::table((new TestMediaModel)->getTable())->insert([
        ['id' => $ownerA, 'tenant_id' => MediaTenancyScenario::A, 'name' => 'A', 'created_at' => $now, 'updated_at' => $now],
        ['id' => $ownerB, 'tenant_id' => MediaTenancyScenario::B, 'name' => 'B', 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table(MediaTables::Media)->insert([
        'id' => $sourceId,
        'filename' => 'legacy.jpg',
        'hash' => $hash,
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => strlen($bytes),
        'disk' => 'tenant-disk',
        'folder' => 'legacy',
        'is_public' => false,
        'visibility' => 'private',
        'status' => 'available',
        'revision' => 1,
        'type' => 'image',
        'digest' => $digest,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table(MediaTables::Associations)->insert([
        ['id' => (string) Str::uuid(), 'media_id' => $sourceId, 'associable_type' => TestMediaModel::class, 'associable_id' => $ownerA, 'collection' => 'default', 'order' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'media_id' => $sourceId, 'associable_type' => TestMediaModel::class, 'associable_id' => $ownerB, 'collection' => 'default', 'order' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table(MediaTables::I18n)->insert([
        'id' => (string) Str::uuid(), 'media_id' => $sourceId, 'locale' => 'en', 'title' => 'Shared', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table(MediaTables::ImageVariations)->insert([
        'id' => (string) Str::uuid(), 'media_id' => $sourceId, 'label' => 'thumb', 'width' => 10, 'height' => 10,
        'size' => 16, 'format' => 'webp', 'quality' => 80, 'source_revision' => 1, 'attempts' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    Storage::disk('tenant-disk')->put($sourcePath, $bytes);
    Storage::disk('tenant-disk')->put($variationPath, 'legacy-variation');

    $plan = $coordinator->prepare(['resource-fixture-owners', 'media'], [
        new TenantAssignment('media.assets', $sourceId, new TenantId(MediaTenancyScenario::A), [
            'expected_digest' => $digest,
            'source_disposition' => 'retain-primary',
            'splits' => [[
                'tenant_id' => MediaTenancyScenario::B,
                'destination_id' => $destinationId,
            ]],
        ]),
    ], $operation);

    expect($coordinator->backfill($plan, 100, $operation))->toBeFalse()
        ->and($coordinator->backfill($plan, 100, $operation))->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    app(MaintenanceMode::class)->deactivate();

    $roots = DB::table(MediaTables::Media)->whereIn('id', [$sourceId, $destinationId])->orderBy('tenant_id')->get();
    $paths = $roots->pluck('storage_path', 'tenant_id');

    expect($roots)->toHaveCount(2)
        ->and($paths[MediaTenancyScenario::A])->toStartWith('media/tenants/'.MediaTenancyScenario::A.'/')
        ->and($paths[MediaTenancyScenario::B])->toStartWith('media/tenants/'.MediaTenancyScenario::B.'/')
        ->and(Storage::disk('tenant-disk')->get($paths[MediaTenancyScenario::A]))->toBe($bytes)
        ->and(Storage::disk('tenant-disk')->get($paths[MediaTenancyScenario::B]))->toBe($bytes)
        ->and(Storage::disk('tenant-disk')->get($sourcePath))->toBe($bytes)
        ->and(DB::table(MediaTables::Associations)->where('tenant_id', MediaTenancyScenario::A)->value('media_id'))->toBe($sourceId)
        ->and(DB::table(MediaTables::Associations)->where('tenant_id', MediaTenancyScenario::B)->value('media_id'))->toBe($destinationId)
        ->and(DB::table(MediaTables::I18n)->whereIn('media_id', [$sourceId, $destinationId])->count())->toBe(2)
        ->and(DB::table(MediaTables::ImageVariations)->whereIn('media_id', [$sourceId, $destinationId])->count())->toBe(2)
        ->and(DB::table(MediaTables::TenantAdoptionCopies)->where('adoption_run_id', $plan->id)->where('status', 'committed')->count())->toBe(2);
});
