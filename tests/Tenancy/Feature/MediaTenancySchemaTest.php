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
