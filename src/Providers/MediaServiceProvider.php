<?php

declare(strict_types=1);

namespace Nvl\Media\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Actions\DetachMediaAction;
use Nvl\Media\Actions\ReusePublicMediaAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Console\Commands\MediaDoctorCommand;
use Nvl\Media\Console\Commands\MigrateDiskCommand;
use Nvl\Media\Console\Commands\PruneExpiredMultipartUploadsCommand;
use Nvl\Media\Console\Commands\RegenerateVariationsCommand;
use Nvl\Media\Console\Commands\StorageHealthCommand;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MediaHostResolver;
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Contracts\ReusePublicMediaContract;
use Nvl\Media\Contracts\UploadMediaContract;
use Nvl\Media\MediaLibrary;
use Nvl\Media\Models\Media;
use Nvl\Media\Policies\MediaPolicy;
use Nvl\Media\Services\DefaultMediaAuthorization;
use Nvl\Media\Services\ImageOptimizationService;
use Nvl\Media\Services\MediaAccessService;
use Nvl\Media\Services\MediaAssetService;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaDoctor;
use Nvl\Media\Services\MediaFileEffectScheduler;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaFileTypePolicy;
use Nvl\Media\Services\MediaImageTransformer;
use Nvl\Media\Services\MediaIngestionPipeline;
use Nvl\Media\Services\MediaLifecycleService;
use Nvl\Media\Services\MediaLocaleResolver;
use Nvl\Media\Services\MediaLocalFileMaterializer;
use Nvl\Media\Services\MediaModelInteractionService;
use Nvl\Media\Services\MediaMultipartLock;
use Nvl\Media\Services\MediaMultipartService;
use Nvl\Media\Services\MediaMultipartSessionMapper;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaMutationService;
use Nvl\Media\Services\MediaOwnedSourceLifecycle;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaPrivilegedAccess;
use Nvl\Media\Services\MediaQueryService;
use Nvl\Media\Services\MediaReplacementStager;
use Nvl\Media\Services\MediaResourceDataFactory;
use Nvl\Media\Services\MediaScannerPolicy;
use Nvl\Media\Services\MediaSourceResolver;
use Nvl\Media\Services\MediaTemporaryFileRegistry;
use Nvl\Media\Services\MediaTransactionRollbackRegistry;
use Nvl\Media\Services\MediaUploadValidator;
use Nvl\Media\Services\MediaUrlResolver;
use Nvl\Media\Services\MediaVariationDefinitionNormalizer;
use Nvl\Media\Services\NullMediaContentScanner;
use Nvl\Media\Services\PortableMediaSearchDriver;
use Nvl\Media\Services\SvgScanner;
use Nvl\Media\Services\SystemMediaHostResolver;
use Nvl\Media\Services\UnsupportedMultipartUploadGateway;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/** Registers Media configuration, migrations, contracts, and optional routes. */
final class MediaServiceProvider extends ServiceProvider
{
    /**
     * Boot Media resources and its single transaction rollback listener.
     */
    public function boot(
        TranslationResourceRegistry $translationResources,
        TypeScriptSourceRegistry $typeScriptSources,
        Dispatcher $events,
        MediaTransactionRollbackRegistry $rollbackCallbacks,
    ): void {
        $events->listen(
            TransactionRolledBack::class,
            $rollbackCallbacks->handle(...),
        );
        $typeScriptSources->register(__DIR__.'/..', 'nvl/media');

        $this->registerPolicies();
        $this->registerTranslations();
        $this->registerConfig();
        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'media-migrations');
        if ((bool) config('media.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }
        $translationResources->register(
            key: 'media.assets',
            modelClass: Media::class,
            label: 'Media assets',
            searchableColumns: ['filename', 'mime_type', 'digest'],
            displayColumns: ['filename', 'type', 'is_public'],
            orderColumn: 'created_at',
        );

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'media-skills');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateDiskCommand::class,
                MediaDoctorCommand::class,
                PruneExpiredMultipartUploadsCommand::class,
                RegenerateVariationsCommand::class,
                StorageHealthCommand::class,
            ]);
        }
    }

    /**
     * Register Media configuration, routes, and container bindings.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../../config/media.php', 'media');
        $this->app->register(RouteServiceProvider::class);
        $this->app->singleton(MediaTransactionRollbackRegistry::class);
        $this->registerScopedServices();
    }

    /**
     * Register services as scoped bindings resolved once per request.
     */
    protected function registerScopedServices(): void
    {
        $this->app->scoped(MediaAccessService::class);
        $this->app->scoped(MediaDiskGateway::class);
        $this->app->scoped(MediaDoctor::class);
        $this->app->scoped(MediaFileEffectScheduler::class);
        $this->app->scoped(MediaFileExistence::class);
        $this->app->scoped(MediaFileOperator::class);
        $this->app->scoped(MediaFileTypePolicy::class);
        $this->app->scoped(MediaLocalFileMaterializer::class);
        $this->app->scoped(MediaMutationLock::class);
        $this->app->scoped(MediaMultipartLock::class);
        $this->app->scoped(MediaMultipartSessionMapper::class);
        $this->app->scoped(MediaMultipartService::class);
        $this->app->scoped(MediaOwnedSourceLifecycle::class);
        $this->app->scoped(MediaTemporaryFileRegistry::class);
        $this->app->scoped(MediaPathResolver::class);
        $this->app->scoped(MediaUrlResolver::class);
        $this->app->scoped(MediaImageTransformer::class);
        $this->app->scoped(MediaIngestionPipeline::class);
        $this->app->scoped(ImageOptimizationService::class);
        $this->app->scoped(MediaAssetService::class);
        $this->app->scoped(MediaSourceResolver::class);
        $this->app->scoped(MediaLifecycleService::class);
        $this->app->scoped(MediaModelInteractionService::class);
        $this->app->scoped(MediaMutationService::class);
        $this->app->scoped(MediaPrivilegedAccess::class);
        $this->app->scoped(MediaQueryService::class);
        $this->app->scoped(MediaReplacementStager::class);
        $this->app->scoped(MediaResourceDataFactory::class);
        $this->app->scoped(SvgScanner::class);
        $this->app->scoped(MediaLocaleResolver::class);
        $this->app->scoped(MediaUploadValidator::class);
        $this->app->scoped(MediaVariationDefinitionNormalizer::class);
        $this->app->scoped(MediaScannerPolicy::class);

        $this->app->bind(
            MediaContentScanner::class,
            MediaConfiguration::string(
                'media.content_scanner',
                NullMediaContentScanner::class,
            ),
        );
        $this->app->bind(MediaAuthorization::class, DefaultMediaAuthorization::class);
        $this->app->bind(MediaHostResolver::class, SystemMediaHostResolver::class);
        $this->app->bind(
            MediaSearchDriver::class,
            MediaConfiguration::string(
                'media.query.search_driver',
                PortableMediaSearchDriver::class,
            ),
        );
        $multipartGateway = (bool) config('media.multipart.enabled', false)
            ? MediaConfiguration::string(
                'media.multipart.gateway',
                UnsupportedMultipartUploadGateway::class,
            )
            : UnsupportedMultipartUploadGateway::class;
        $this->app->bind(MultipartUploadGateway::class, $multipartGateway);
        $this->app->bind(UploadMediaContract::class, UploadMediaAction::class);
        $this->app->bind(DeleteMediaContract::class, DeleteMediaAction::class);
        $this->app->bind(AttachMediaContract::class, AttachMediaAction::class);
        $this->app->bind(DetachMediaContract::class, DetachMediaAction::class);
        $this->app->bind(ReusePublicMediaContract::class, ReusePublicMediaAction::class);
        $this->app->scoped(MediaLibraryContract::class, MediaLibrary::class);
    }

    protected function registerConfig(): void
    {
        $config = [
            __DIR__.'/../../config/media.php' => config_path('media.php'),
        ];

        $this->publishes($config, 'media-config');
        $this->publishes($config, 'config');
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Media::class, MediaPolicy::class);
    }

    protected function registerTranslations(): void
    {
        $langPath = __DIR__.'/../../lang';

        $this->loadTranslationsFrom($langPath, 'media');
        $this->loadJsonTranslationsFrom($langPath);

        $this->publishes([
            $langPath => lang_path('vendor/media'),
        ], 'media-translations');
    }

    /**
     * @return array<string>
     */
    public function provides(): array
    {
        return [];
    }
}
