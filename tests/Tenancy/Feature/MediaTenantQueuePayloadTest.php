<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Models\Media;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

it('persists a scalar variation job with the producing tenant envelope', function (): void {
    $scenario = MediaTenancyScenario::install();
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config()->set('queue.default', 'database');
    config()->set('media.queue.connection', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);

    $media = $scenario->run($scenario::A, fn (): Media => Media::factory()->create([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'storage_path' => 'media/tenants/'.$scenario::A.'/fixture/image.jpg',
    ]));
    $scenario->run($scenario::A, fn () => GenerateImageVariationJob::dispatch(
        $media->id,
        'thumb',
        ['width' => 100, 'height' => 100],
        $media->revision,
        TenantJobEnvelope::capture(app(TenantContext::class)),
    ));

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    $serialized = (string) data_get($payload, 'data.command');

    expect(data_get($payload, 'data.nvl_tenancy.tenant_id'))->toBe($scenario::A)
        ->and($serialized)->toContain($media->id)
        ->not->toContain('Nvl\\Media\\Models\\Media');
});

it('requires an explicit tenant worklist for regeneration', function (): void {
    MediaTenancyScenario::install();

    $this->artisan('nvl:media:regenerate', [
        '--dry-run' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('requires exactly one of --tenant or --all-tenants')
        ->assertExitCode(2);
});

it('regenerates only inside the requested tenant boundary', function (): void {
    $scenario = MediaTenancyScenario::install();
    $scenario->run($scenario::A, fn (): Media => Media::factory()->create([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'type' => MediaType::IMAGE,
        'storage_path' => 'media/tenants/'.$scenario::A.'/fixture/a.jpg',
    ]));
    $scenario->run($scenario::B, fn (): Media => Media::factory()->create([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'type' => MediaType::IMAGE,
        'storage_path' => 'media/tenants/'.$scenario::B.'/fixture/b.jpg',
    ]));

    $this->artisan('nvl:media:regenerate', [
        '--tenant' => $scenario::A,
        '--dry-run' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutput('Found 1 media records matching filters.')
        ->expectsOutput('[dry-run] Would regenerate variations for 1 media records.')
        ->assertSuccessful();
});

it('enumerates all tenants only through the host worklist contract', function (): void {
    $scenario = MediaTenancyScenario::install();
    config()->set('media.tenancy.active_tenant_worklist', [$scenario::B, $scenario::A]);
    foreach ([$scenario::A, $scenario::B] as $tenant) {
        $scenario->run($tenant, fn (): Media => Media::factory()->create([
            ...app(TenantBoundary::class)->attributes('media.assets'),
            'type' => MediaType::IMAGE,
            'storage_path' => 'media/tenants/'.$tenant.'/fixture/worklist.jpg',
        ]));
    }

    $this->artisan('nvl:media:regenerate', [
        '--all-tenants' => true,
        '--dry-run' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutput('Tenant '.$scenario::A)
        ->expectsOutput('Tenant '.$scenario::B)
        ->expectsOutput('[dry-run] Would regenerate variations for 1 media records.')
        ->assertSuccessful();
});
