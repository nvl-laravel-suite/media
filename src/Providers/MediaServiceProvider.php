<?php

declare(strict_types=1);

namespace Nvl\Media\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Media\Actions\AbortMultipartUploadAction;
use Nvl\Media\Actions\AdoptSpatieMediaAction;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\BulkDeleteMediaAction;
use Nvl\Media\Actions\BulkMoveMediaAction;
use Nvl\Media\Actions\BulkTagMediaAction;
use Nvl\Media\Actions\ClearOwnerMediaSlotAction;
use Nvl\Media\Actions\CompleteMultipartUploadAction;
use Nvl\Media\Actions\CopyOwnerMediaSlotAction;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Actions\DetachMediaAction;
use Nvl\Media\Actions\FinalizeMediaScanAction;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Actions\GetOwnerMediaSlotAction;
use Nvl\Media\Actions\GrantMediaToTenantAction;
use Nvl\Media\Actions\ImportPlatformMediaAction;
use Nvl\Media\Actions\InitiateMultipartUploadAction;
use Nvl\Media\Actions\ListOwnerMediaAction;
use Nvl\Media\Actions\ListOwnerMediaSlotsAction;
use Nvl\Media\Actions\MutateMediaTagsAction;
use Nvl\Media\Actions\RelocateMediaAction;
use Nvl\Media\Actions\RemoveOwnerMediaAction;
use Nvl\Media\Actions\RenameMediaAction;
use Nvl\Media\Actions\ReplaceMediaFileAction;
use Nvl\Media\Actions\ReplaceOwnerMediaSlotAction;
use Nvl\Media\Actions\ReusePublicMediaAction;
use Nvl\Media\Actions\RevokeMediaTenantGrantAction;
use Nvl\Media\Actions\SignMultipartPartAction;
use Nvl\Media\Actions\UpdateMediaMetadataAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Console\Commands\AdoptSpatieMediaCommand;
use Nvl\Media\Console\Commands\MediaDoctorCommand;
use Nvl\Media\Console\Commands\MigrateDiskCommand;
use Nvl\Media\Console\Commands\PruneExpiredMultipartUploadsCommand;
use Nvl\Media\Console\Commands\PruneMediaOwnerSlotOperationsCommand;
use Nvl\Media\Console\Commands\RegenerateVariationsCommand;
use Nvl\Media\Console\Commands\StorageHealthCommand;
use Nvl\Media\Contracts\AbortMultipartUploadContract;
use Nvl\Media\Contracts\AdoptSpatieMediaContract;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\BulkDeleteMediaContract;
use Nvl\Media\Contracts\BulkMoveMediaContract;
use Nvl\Media\Contracts\BulkTagMediaContract;
use Nvl\Media\Contracts\ClearOwnerMediaSlotContract;
use Nvl\Media\Contracts\CompleteMultipartUploadContract;
use Nvl\Media\Contracts\CopyOwnerMediaSlotContract;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\FinalizeMediaScanContract;
use Nvl\Media\Contracts\GenerateImageVariationContract;
use Nvl\Media\Contracts\GetOwnerMediaSlotContract;
use Nvl\Media\Contracts\GrantMediaToTenantContract;
use Nvl\Media\Contracts\ImportPlatformMediaContract;
use Nvl\Media\Contracts\InitiateMultipartUploadContract;
use Nvl\Media\Contracts\ListOwnerMediaContract;
use Nvl\Media\Contracts\ListOwnerMediaSlotsContract;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Contracts\MediaCatalogAccess;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Contracts\MediaHostResolver;
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Contracts\MediaTenantWorklist;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Contracts\MutateMediaTagsContract;
use Nvl\Media\Contracts\RelocateMediaContract;
use Nvl\Media\Contracts\RemoveOwnerMediaContract;
use Nvl\Media\Contracts\RenameMediaContract;
use Nvl\Media\Contracts\ReplaceMediaFileContract;
use Nvl\Media\Contracts\ReplaceOwnerMediaSlotContract;
use Nvl\Media\Contracts\ReusePublicMediaContract;
use Nvl\Media\Contracts\RevokeMediaTenantGrantContract;
use Nvl\Media\Contracts\SignMultipartPartContract;
use Nvl\Media\Contracts\UpdateMediaMetadataContract;
use Nvl\Media\Contracts\UploadMediaContract;
use Nvl\Media\Events\MediaUploaded;
use Nvl\Media\Events\MediaUploadedEvent;
use Nvl\Media\MediaLibrary;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Media\Ownership\MediaAdoptionAdapter as OwnershipMediaAdoptionAdapter;
use Nvl\Media\Ownership\MediaDeliveryGuard;
use Nvl\Media\Ownership\MediaOwnerCatalog;
use Nvl\Media\Ownership\MediaOwnershipParentResolver;
use Nvl\Media\Ownership\MediaResourceRegistrar;
use Nvl\Media\Policies\MediaPolicy;
use Nvl\Media\Services\ConfiguredMediaTenantWorklist;
use Nvl\Media\Services\DefaultMediaAuthorization;
use Nvl\Media\Services\DenyMediaCatalogAccess;
use Nvl\Media\Services\ImageOptimizationService;
use Nvl\Media\Services\MediaAccessService;
use Nvl\Media\Services\MediaAssetService;
use Nvl\Media\Services\MediaCatalogImporter;
use Nvl\Media\Services\MediaCatalogReader;
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
use Nvl\Media\Services\MediaManagementDataFactory;
use Nvl\Media\Services\MediaModelInteractionService;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaMultipartLock;
use Nvl\Media\Services\MediaMultipartService;
use Nvl\Media\Services\MediaMultipartSessionMapper;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaMutationService;
use Nvl\Media\Services\MediaOwnedSourceLifecycle;
use Nvl\Media\Services\MediaOwnerFactory;
use Nvl\Media\Services\MediaOwnerSlotIdempotency;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaPrivilegedAccess;
use Nvl\Media\Services\MediaQueryService;
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
use Nvl\Media\Tenancy\MediaAdoptionAdapter;
use Nvl\Media\Tenancy\MediaLegacyGrantScope;
use Nvl\Media\Tenancy\MediaTenancyResources;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Events\EventAliases;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
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
        TypeScriptSourceRegistry $typeScriptSources,
        Dispatcher $events,
        MediaTransactionRollbackRegistry $rollbackCallbacks,
        MediaTenancyResources $tenancyResources,
        TenantResourceRegistry $tenantResourceRegistry,
        OwnershipBoundary $tenantBoundary,
    ): void {
        $this->app->make(EventAliases::class)->register(MediaUploaded::class, MediaUploadedEvent::class);
        $events->listen(
            TransactionRolledBack::class,
            $rollbackCallbacks->handle(...),
        );
        $typeScriptSources->register(__DIR__.'/..', 'nvl/media');
        $tenancyResources->register($tenantResourceRegistry);
        $this->app->make(MediaResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class));
        if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
            $this->app->make(OwnershipAdoptionRegistry::class)->register('media', OwnershipMediaAdoptionAdapter::class);
        }
        if ($this->app->bound(TenantAdoptionRegistry::class)) {
            $this->app->make(TenantAdoptionRegistry::class)->register('media', MediaAdoptionAdapter::class);
        }

        MediaTenantGrant::addGlobalScope('tenant', new MediaLegacyGrantScope);
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
        $this->app->register(RouteServiceProvider::class);
        $this->app->singleton(MediaTransactionRollbackRegistry::class);
        $this->app->bind(MediaOwnerFactory::class, fn (): MediaOwnerFactory => new MediaOwnerFactory(
            fn (): MediaModelInteractionService => $this->app->make(MediaModelInteractionService::class),
            fn (): MediaLifecycleService => $this->app->make(MediaLifecycleService::class),
        ));
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
        $this->app->scoped(MediaCatalogReader::class);
        $this->app->scoped(MediaOwnerCatalog::class);
        $this->app->bindIf(MediaCatalogAccess::class, DenyMediaCatalogAccess::class);
        $this->app->scopedIf(MediaCatalogImporter::class);
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
        $this->app->scoped(MediaMultipartService::class);
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
        $this->app->scoped(MediaLifecycleService::class);
        $this->app->scoped(MediaModelInteractionService::class);
        $this->app->scoped(MediaMutationService::class);
        $this->app->scoped(MediaPrivilegedAccess::class);
        $this->app->scoped(MediaQueryService::class);
        $this->app->scoped(MediaReplacementStager::class);
        $this->app->scoped(MediaManagementDataFactory::class);
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
        $this->app->bindIf(ListOwnerMediaSlotsContract::class, ListOwnerMediaSlotsAction::class);
        $this->app->bindIf(ListOwnerMediaContract::class, ListOwnerMediaAction::class);
        $this->app->bindIf(RemoveOwnerMediaContract::class, RemoveOwnerMediaAction::class);
        $this->app->scopedIf(MediaCatalogImport::class, MediaCatalogImporter::class);
        $this->app->bindIf(MediaTenantWorklist::class, ConfiguredMediaTenantWorklist::class);
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
        $this->app->bindIf(UploadMediaContract::class, UploadMediaAction::class);
        $this->app->bindIf(DeleteMediaContract::class, DeleteMediaAction::class);
        $this->app->bindIf(AttachMediaContract::class, AttachMediaAction::class);
        $this->app->bindIf(DetachMediaContract::class, DetachMediaAction::class);
        $this->app->bindIf(ReusePublicMediaContract::class, ReusePublicMediaAction::class);
        $this->app->bindIf(AbortMultipartUploadContract::class, AbortMultipartUploadAction::class);
        $this->app->bindIf(AdoptSpatieMediaContract::class, AdoptSpatieMediaAction::class);
        $this->app->bindIf(BulkDeleteMediaContract::class, BulkDeleteMediaAction::class);
        $this->app->bindIf(BulkMoveMediaContract::class, BulkMoveMediaAction::class);
        $this->app->bindIf(BulkTagMediaContract::class, BulkTagMediaAction::class);
        $this->app->bindIf(ClearOwnerMediaSlotContract::class, ClearOwnerMediaSlotAction::class);
        $this->app->bindIf(CompleteMultipartUploadContract::class, CompleteMultipartUploadAction::class);
        $this->app->bindIf(CopyOwnerMediaSlotContract::class, CopyOwnerMediaSlotAction::class);
        $this->app->bindIf(FinalizeMediaScanContract::class, FinalizeMediaScanAction::class);
        $this->app->bindIf(GenerateImageVariationContract::class, GenerateImageVariationAction::class);
        $this->app->bindIf(GetOwnerMediaSlotContract::class, GetOwnerMediaSlotAction::class);
        $this->app->bindIf(GrantMediaToTenantContract::class, GrantMediaToTenantAction::class);
        $this->app->bindIf(ImportPlatformMediaContract::class, ImportPlatformMediaAction::class);
        $this->app->bindIf(InitiateMultipartUploadContract::class, InitiateMultipartUploadAction::class);
        $this->app->bindIf(MutateMediaTagsContract::class, MutateMediaTagsAction::class);
        $this->app->bindIf(RelocateMediaContract::class, RelocateMediaAction::class);
        $this->app->bindIf(RenameMediaContract::class, RenameMediaAction::class);
        $this->app->bindIf(ReplaceMediaFileContract::class, ReplaceMediaFileAction::class);
        $this->app->bindIf(ReplaceOwnerMediaSlotContract::class, ReplaceOwnerMediaSlotAction::class);
        $this->app->bindIf(RevokeMediaTenantGrantContract::class, RevokeMediaTenantGrantAction::class);
        $this->app->bindIf(SignMultipartPartContract::class, SignMultipartPartAction::class);
        $this->app->bindIf(UpdateMediaMetadataContract::class, UpdateMediaMetadataAction::class);
        $this->app->scopedIf(MediaLibraryContract::class, MediaLibrary::class);
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
