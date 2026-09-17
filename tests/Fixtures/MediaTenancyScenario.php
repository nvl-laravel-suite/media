<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Installs the complete Media graph through the real adoption coordinator. */
final readonly class MediaTenancyScenario
{
    public const string A = '00000000-0000-4000-8000-00000000000a';

    public const string B = '00000000-0000-4000-8000-00000000000b';

    /** Adopt Media plus its canonical owner fixture. */
    public static function install(bool $catalogCopies = false): self
    {
        expect(config('tenancy.enabled'))->toBeTrue()
            ->and(config('tenancy.sharing.media'))->toBe($catalogCopies ? 'copy' : 'none');

        app(TenantResourceRegistry::class)->get('test.media-owners');

        $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
        $coordinator = app(TenantAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['resource-fixture-owners', 'media'], [], $operation);
        $done = false;
        for ($batch = 0; $batch < 20 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        expect($done)->toBeTrue();
        expect($coordinator->verify($plan)->passed())->toBeTrue();
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();

        return new self;
    }

    /**
     * Run one callback under an active tenant.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $tenant, Closure $callback): mixed
    {
        return app(TenantRunner::class)->run(new TenantId($tenant), $callback);
    }

    /**
     * Run one explicit catalog operation.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function platform(Closure $callback): mixed
    {
        return app(TenantRunner::class)->platform(new PlatformOperation('fixture.catalog', 'test', 'fixture'), $callback);
    }

    /** Create one canonical registered Media owner. */
    public function owner(string $tenant): TestMediaModel
    {
        return $this->run($tenant, static function (): TestMediaModel {
            $owner = new TestMediaModel(['name' => 'Owner']);
            $owner->forceFill(app(TenantBoundary::class)->attributes('test.media-owners'));
            $owner->save();

            return $owner->refresh();
        });
    }
}
