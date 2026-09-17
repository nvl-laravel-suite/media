<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Media\Models\Media;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Tenancy\Services\TenantBoundary;

it('rejects an association with a tenant different from its asset', function (): void {
    $scenario = MediaTenancyScenario::install();
    $asset = $scenario->run($scenario::A, fn (): Media => Media::factory()->create([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'storage_path' => 'media/tenants/'.$scenario::A.'/fixture/schema.jpg',
    ]));
    $ownerB = $scenario->owner($scenario::B);

    expect(array_any(
        Schema::getForeignKeys('px_media_associations'),
        static fn (mixed $foreign): bool => is_array($foreign) && ($foreign['columns'] ?? null) === ['tenant_id', 'media_id'],
    ))->toBeTrue();

    expect(fn () => DB::table('px_media_associations')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $scenario::B,
        'media_id' => $asset->id,
        'associable_type' => $ownerB->getMorphClass(),
        'associable_id' => $ownerB->getKey(),
        'collection' => 'default',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('requires an authoritative persisted root path after activation', function (): void {
    $scenario = MediaTenancyScenario::install();

    expect(fn () => DB::table('px_media')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $scenario::A,
        'filename' => 'missing.txt', 'hash' => 'missing.txt', 'extension' => 'txt',
        'mime_type' => 'text/plain', 'size' => 1, 'disk' => 'tenant-disk', 'folder' => 'fixture',
        'is_public' => false, 'visibility' => 'private', 'status' => 'available', 'revision' => 1,
        'type' => 'document', 'digest' => hash('sha256', 'x'), 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('reverses and reapplies every final ownership constraint', function (): void {
    MediaTenancyScenario::install();
    $migration = require dirname(__DIR__, 3).'/database/tenancy/2026_09_16_100003_constrain_media_tenant_ownership.php';

    $migration->down();
    $columns = collect(Schema::getColumns('px_media'))->keyBy('name');
    expect($columns['tenant_id']['nullable'])->toBeTrue()
        ->and($columns['storage_path']['nullable'])->toBeTrue()
        ->and(Schema::hasIndex('px_media', 'media_partition_id_unique'))->toBeFalse()
        ->and(array_any(
            Schema::getForeignKeys('px_media_associations'),
            static fn (array $foreign): bool => $foreign['name'] === 'px_media_associations_media_partition_foreign',
        ))->toBeFalse();

    $migration->up();
    expect(Schema::hasIndex('px_media', 'media_partition_id_unique'))->toBeTrue();
});
