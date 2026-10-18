<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Models\MediaOwnerGrant;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/** Compiles Media's complete graph from its configured consumer root. @internal */
final readonly class MediaResourceRegistrar
{
    public function __construct(private MediaModelRegistry $models) {}

    /** Leave ordinary applications unscoped; owning consumers explicitly extend Media. */
    public function register(OwnershipResourceRegistry $resources): void
    {
        $class = $this->models->mediaClass();
        $ownership = $class::ownershipDefinition();
        $owned = $ownership->strategy !== 'unscoped';
        $resources->register(new OwnershipResourceDefinition('media.assets', 'media', $class, $ownership, allowsCentral: $owned && $class::allowsCentralCatalog()));
        foreach (['media.associations' => MediaAssociation::class, 'media.variations' => MediaImageVariation::class, 'media.translations' => MediaTranslation::class] as $key => $model) {
            $resources->register(new OwnershipResourceDefinition($key, 'media', $model, $owned ? OwnershipDefinition::inherited('media') : OwnershipDefinition::unscoped(), parentResource: $owned ? 'media.assets' : null, allowsCentral: $owned && $class::allowsCentralCatalog(), immutableFields: $owned && $key === 'media.associations' ? ['associable_type', 'associable_id'] : []));
        }
        foreach (['media.multipart' => MediaMultipartUpload::class, 'media.slot-operations' => MediaOwnerSlotOperation::class, 'media.catalog-grants' => MediaOwnerGrant::class] as $key => $model) {
            $resources->register(new OwnershipResourceDefinition($key, 'media', $model, $owned ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped()));
        }
    }
}
