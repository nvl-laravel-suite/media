<?php

declare(strict_types=1);

namespace Nvl\Media\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nvl\Media\Console\Commands\AdoptSpatieMediaCommand;
use Nvl\Media\Console\Commands\MediaDoctorCommand;
use Nvl\Media\Console\Commands\MigrateDiskCommand;
use Nvl\Media\Console\Commands\PruneExpiredMultipartUploadsCommand;
use Nvl\Media\Console\Commands\PruneMediaOwnerSlotOperationsCommand;
use Nvl\Media\Console\Commands\RegenerateVariationsCommand;
use Nvl\Media\Console\Commands\StorageHealthCommand;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Contracts\MediaCatalogAccess;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MediaHostResolver;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Models\Media;
use Nvl\Media\Ownership\MediaAdoptionAdapter as OwnershipMediaAdoptionAdapter;
use Nvl\Media\Ownership\MediaDeliveryGuard;
use Nvl\Media\Ownership\MediaOwnerCatalog;
use Nvl\Media\Ownership\MediaOwnershipParentResolver;
use Nvl\Media\Ownership\MediaResourceRegistrar;
use Nvl\Media\Policies\MediaPolicy;
use Nvl\Media\Services\DefaultMediaAuthorization;
use Nvl\Media\Services\DenyMediaCatalogAccess;
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
use Nvl\Media\Services\MediaLocaleResolver;
use Nvl\Media\Services\MediaLocalFileMaterializer;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaMultipartLock;
use Nvl\Media\Services\MediaMultipartSessionMapper;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaOwnedSourceLifecycle;
use Nvl\Media\Services\MediaOwnerSlotIdempotency;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaPrivilegedAccess;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Services\MediaReplacementStager;
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
use Nvl\Media\Services\UnsupportedMediaBatchAuthorization;
use Nvl\Media\Services\UnsupportedMultipartUploadGateway;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/** Registers Media configuration, migrations, contracts, and optional routes. */
final class MediaServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    private const string OWNER_SLOT_COPY_METADATA_KEYS = 'media.owner_slots.copy.metadata_keys';

    /**
     * Boot Media resources and its single transaction rollback listener.
     */
    public function boot(
        TranslationResourceRegistry $translationResources,
        Dispatcher $events,
        MediaTransactionRollbackRegistry $rollbackCallbacks,
        OwnershipBoundary $tenantBoundary,
    ): void {
        $events->listen(
            TransactionRolledBack::class,
            $rollbackCallbacks->handle(...),
        );
        $this->app->make(MediaResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class));
        if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
            $this->app->make(OwnershipAdoptionRegistry::class)->register('media', OwnershipMediaAdoptionAdapter::class);
        }

        $this->registerPolicies();
        $this->registerTranslations();
        $this->registerConfig();
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'media-migrations');
        if ((bool) config('nvl-media.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }
        $translationResources->register(
            key: 'media.assets',
            modelClass: $this->app->make(MediaModelRegistry::class)->mediaClass(),
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
                AdoptSpatieMediaCommand::class,
                MigrateDiskCommand::class,
                MediaDoctorCommand::class,
                PruneExpiredMultipartUploadsCommand::class,
                PruneMediaOwnerSlotOperationsCommand::class,
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
        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/media', fn (): array => $this->app->make(MediaDoctor::class)->inspect());

        $this->mergeConfiguration();
        $this->app->singleton(MediaModelRegistry::class);
        $this->app->booting(fn () => $this->app->make(MediaModelRegistry::class)->prepare());
        $this->app->singleton(MediaTransactionRollbackRegistry::class);
        $this->registerScopedServices();
    }

    /**
     * Merge package defaults while preserving the consumer metadata allowlist.
     */
    private function mergeConfiguration(): void
    {
        /** @var Repository $configuration */
        $configuration = $this->app->make('config');
        $hasConsumerMetadataKeys = $configuration->has(
            self::OWNER_SLOT_COPY_METADATA_KEYS,
        );
        $consumerMetadataKeys = $configuration->get(
            self::OWNER_SLOT_COPY_METADATA_KEYS,
        );

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-media.php', 'media');

        if ($hasConsumerMetadataKeys) {
            $configuration->set(
                self::OWNER_SLOT_COPY_METADATA_KEYS,
                $consumerMetadataKeys,
            );
        }
    }

    /**
     * Register services as scoped bindings resolved once per request.
     */
    protected function registerScopedServices(): void
    {
        $this->app->scoped(MediaAccessService::class);
        $this->app->scoped(MediaOwnerCatalog::class);
        $this->app->bindIf(MediaCatalogAccess::class, DenyMediaCatalogAccess::class);
        $this->app->scoped(MediaDeliveryGuard::class);
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
        $this->app->scoped(MediaOwnedSourceLifecycle::class);
        $this->app->scoped(MediaOwnerSlotIdempotency::class);
        $this->app->scoped(MediaTemporaryFileRegistry::class);
        $this->app->scoped(MediaOwnershipParentResolver::class);
        $this->app->scoped(MediaPathResolver::class);
        $this->app->scoped(MediaUrlResolver::class);
        $this->app->scoped(MediaImageTransformer::class);
        $this->app->scoped(MediaIngestionPipeline::class);
        $this->app->scoped(ImageOptimizationService::class);
        $this->app->scoped(MediaAssetService::class);
        $this->app->scoped(MediaSourceResolver::class);
        $this->app->scoped(MediaPrivilegedAccess::class);
        $this->app->scoped(MediaReader::class);
        $this->app->scoped(MediaReplacementStager::class);
        $this->app->scoped(SvgScanner::class);
        $this->app->scoped(MediaLocaleResolver::class);
        $this->app->scoped(MediaUploadValidator::class);
        $this->app->scoped(MediaVariationDefinitionNormalizer::class);
        $this->app->scoped(MediaScannerPolicy::class);

        $this->app->bindIf(
            MediaContentScanner::class,
            MediaConfiguration::string(
                'nvl-media.content_scanner',
                NullMediaContentScanner::class,
            ),
        );
        $this->app->bindIf(MediaAuthorization::class, DefaultMediaAuthorization::class);
        $this->app->bindIf(MediaBatchAuthorization::class, UnsupportedMediaBatchAuthorization::class);

        $this->app->scopedIf(MediaCatalogImport::class, MediaOwnerCatalog::class);
        $this->app->bindIf(MediaHostResolver::class, SystemMediaHostResolver::class);
        $this->app->bindIf(
            MediaSearchDriver::class,
            MediaConfiguration::string(
                'nvl-media.query.search_driver',
                PortableMediaSearchDriver::class,
            ),
        );
        $multipartGateway = (bool) config('nvl-media.multipart.enabled', false)
            ? MediaConfiguration::string(
                'nvl-media.multipart.gateway',
                UnsupportedMultipartUploadGateway::class,
            )
            : UnsupportedMultipartUploadGateway::class;
        $this->app->bindIf(MultipartUploadGateway::class, $multipartGateway);

    }

    protected function registerConfig(): void
    {
        $config = [
            __DIR__.'/../../config/nvl-media.php' => config_path('nvl-media.php'),
        ];

        $this->publishes($config, 'media-config');
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Media::class, MediaPolicy::class);
    }

    protected function registerTranslations(): void
    {
        $langPath = __DIR__.'/../../lang';

        $this->app->make(GlobalNames::class)->translations('media', $langPath, $this->app->make('translation.loader'));

        $this->publishes([
            $langPath => lang_path('vendor/nvl-media'),
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
