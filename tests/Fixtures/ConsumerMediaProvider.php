<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\DefaultMediaAuthorization;
use Nvl\Media\Tests\Fixtures\Consumer\Contracts\MediaLibraryContract;
use Nvl\Media\Tests\Fixtures\Consumer\MediaLibrary;
use Nvl\Media\Tests\Fixtures\Consumer\Services\MediaLifecycleService;
use Nvl\Media\Tests\Fixtures\Consumer\Services\MediaModelInteractionService;
use Nvl\Media\Tests\Fixtures\Consumer\Services\MediaOwnerFactory;
use Nvl\Support\Enums\SystemActor;

/** Host-owned HTTP and fluent orchestration used only by retained consumer scenarios. */
final class ConsumerMediaProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(ConsumerMediaRoutes::class);
        $this->app->bind(MediaAuthorization::class, static fn () => new class implements MediaAuthorization
        {
            public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
            {
                return $actor instanceof SystemActor || (new DefaultMediaAuthorization)->allows($actor, $ability, $media, $owner);
            }
        });
        $this->app->scoped(MediaLibraryContract::class, MediaLibrary::class);
        $this->app->bind(MediaOwnerFactory::class, fn (): MediaOwnerFactory => new MediaOwnerFactory(
            fn (): MediaModelInteractionService => $this->app->make(MediaModelInteractionService::class),
            fn (): MediaLifecycleService => $this->app->make(MediaLifecycleService::class),
        ));
    }
}
