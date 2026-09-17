<?php

declare(strict_types=1);

namespace Nvl\Media\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers Media's immutable complete tenant ownership graph. */
final readonly class MediaTenancyResources
{
    /** Create the configuration-backed resource declaration. */
    public function __construct(private Repository $configuration) {}

    /** Register every stateful Media root and inherited child. */
    public function register(TenantResourceRegistry $resources): void
    {
        $sharing = $this->configuration->get('tenancy.sharing.media', 'none');
        $mode = $this->configuration->get('tenancy.resources.media', 'tenant');
        if (! in_array($sharing, ['none', 'copy'], true) || ! in_array($mode, ['tenant', 'platform'], true)) {
            throw new TenantConfigurationInvalid('Media tenancy configuration is invalid.');
        }
        $mixed = $sharing === 'copy';
        $platform = $mode === 'platform';

        $resources->registerParentResolver('media.slot-operations', MediaTenantParentResolver::class);

        $definitions = [
            new TenantResourceDefinition('media.assets', 'media', Media::class, allowsPlatformCatalog: $mixed, allowsPlatformRows: $platform),
            new TenantResourceDefinition('media.associations', 'media', MediaAssociation::class, TenantResourceKind::Inherited, 'media.assets', 'media'),
            new TenantResourceDefinition('media.variations', 'media', MediaImageVariation::class, TenantResourceKind::Inherited, 'media.assets', 'media'),
            new TenantResourceDefinition('media.translations', 'media', MediaTranslation::class, TenantResourceKind::Inherited, 'media.assets', 'media'),
            new TenantResourceDefinition('media.multipart', 'media', MediaMultipartUpload::class, allowsPlatformRows: $platform),
            new TenantResourceDefinition('media.catalog-grants', 'media.catalog-grants', MediaTenantGrant::class),
        ];
        $ownerTypes = $this->configuration->get('media.tenancy.owner_types', []);
        if ((! $platform && $this->configuration->get('tenancy.enabled') === true) || (is_array($ownerTypes) && $ownerTypes !== [])) {
            $definitions[] = new TenantResourceDefinition('media.slot-operations', 'media', MediaOwnerSlotOperation::class, TenantResourceKind::Inherited, null, 'owner');
        }

        foreach ($definitions as $definition) {
            $resources->register($definition);
        }
    }
}
