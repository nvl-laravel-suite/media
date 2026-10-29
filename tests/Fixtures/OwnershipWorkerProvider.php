<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Services\MediaVariations;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/** Rebuilds the producer's immutable graph in an independent native worker process. */
final class OwnershipWorkerProvider extends ServiceProvider
{
    /** @var array<string, mixed> */
    public static array $source = [];

    public static string $root = '';

    public function register(): void
    {
        $this->app->singleton(OwnershipWorkerMediaAuthorization::class);
        $this->app->bind(MediaAuthorization::class, fn (Application $app): OwnershipWorkerMediaAuthorization => $app->make(OwnershipWorkerMediaAuthorization::class));
        $this->app->make('config')->set(['database.default' => 'sqlite', 'database.connections.sqlite' => self::$source, 'nvl-core.owners' => [OwnershipCompany::class, OwnershipActor::class], 'nvl-ownership.enabled' => true, 'nvl-ownership.migrations.enabled' => false, 'nvl-tenancy.enabled' => false, 'nvl-media.models.media' => OwnershipMedia::class, 'nvl-media.migrations.enabled' => false, 'nvl-media.routes.api_enabled' => false, 'nvl-media.locks.deduplication.store' => 'array', 'nvl-ownership.central_operations' => ['media-maintain'], 'auth.providers.users.model' => OwnershipActor::class, 'filesystems.disks.public' => ['driver' => 'local', 'root' => self::$root, 'throw' => true], 'queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite', 'queue.failed' => ['driver' => 'database-uuids', 'database' => 'sqlite', 'table' => 'failed_jobs'], 'cache.default' => 'database', 'cache.stores.database.connection' => 'sqlite', 'cache.stores.database.lock_connection' => 'sqlite', 'cache.stores.database.table' => 'cache', 'cache.stores.database.lock_table' => 'cache_locks']);
        $this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess
        {
            public function assertCanEnter(Authenticatable $actor, Model $owner): void
            {
                if (! $actor instanceof OwnershipActor || $actor->getAuthIdentifier() !== 1 || ! $owner instanceof OwnershipCompany) {
                    throw new OwnershipViolation;
                }
            }
        });
    }

    public function boot(): void
    {
        $this->app->bindMethod([GenerateImageVariationJob::class, 'handle'], static function (GenerateImageVariationJob $job, Application $app): void {
            $app->make(OwnershipWorkerMediaAuthorization::class)->generate($job, static fn () => $job->handle($app->make(MediaVariations::class)));
        });
        $this->app->make(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('fixture.media-owners', 'fixture', OwnershipMediaOwner::class, OwnershipMediaOwner::ownershipDefinition()));
    }
}
