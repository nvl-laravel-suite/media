<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase;

/** Boots native Media without optional Filterable, Ownership, or consumer HTTP adapters. */
abstract class StandaloneMediaTestCase extends TestCase
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LocaleServiceProvider::class, TranslatableServiceProvider::class, MediaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set(['cache.default' => 'array', 'filesystems.default' => 'local', 'nvl-media.disk' => 'local']);
        $app->bind(MediaAuthorization::class, static fn (): MediaAuthorization => new class implements MediaAuthorization
        {
            public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
            {
                return $actor === SystemActor::Background;
            }
        });
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('standalone_media_owners', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
