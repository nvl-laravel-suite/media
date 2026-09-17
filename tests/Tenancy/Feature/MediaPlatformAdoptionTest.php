<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

it('activates a reviewed legacy graph as the platform partition', function (): void {
    Storage::fake('tenant-disk');
    $id = (string) Str::uuid();
    $bytes = 'platform-legacy';
    $digest = hash('sha256', $bytes);
    $path = 'media/legacy/platform.txt';
    Storage::disk('tenant-disk')->put($path, $bytes);
    DB::table(MediaTables::Media)->insert([
        'id' => $id, 'filename' => 'platform.txt', 'hash' => 'platform.txt', 'extension' => 'txt',
        'mime_type' => 'text/plain', 'size' => strlen($bytes), 'disk' => 'tenant-disk', 'folder' => 'legacy',
        'is_public' => false, 'visibility' => 'private', 'status' => 'available', 'revision' => 1,
        'type' => 'document', 'digest' => $digest, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['media'], [
        new TenantAssignment('media.assets', $id, new TenantId('00000000-0000-4000-8000-00000000000a'), [
            'expected_digest' => $digest,
        ]),
    ], $operation);
    while (! $coordinator->backfill($plan, 100, $operation)) {
    }

    expect($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    app(MaintenanceMode::class)->deactivate();

    $row = DB::table(MediaTables::Media)->where('id', $id)->first();
    expect($row->tenant_id)->toBeNull()
        ->and($row->ownership_key)->toBe('platform')
        ->and($row->storage_path)->toBe($path);
});
