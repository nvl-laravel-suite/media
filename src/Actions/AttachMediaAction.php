<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Events\MediaAttached;
use Nvl\Media\Exceptions\MediaNotReusableException;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaTenantOwnerResolver;
use Nvl\Media\Services\MediaVariationDispatcher;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Attaches a media record to a model via a polymorphic pivot with deduplication.
 */
final class AttachMediaAction implements AttachMediaContract
{
    public function __construct(
        private readonly MediaVariationDispatcher $variationDispatcher,
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaTenantOwnerResolver $owners,
        private readonly TenantBoundary $tenantBoundary,
    ) {}

    /**
     * Attach a Media record to a model via a polymorphic pivot, deduplicating by composite key.
     *
     * After attaching, any model/collection-level conversions that don't already
     * exist as variations for this media will be generated.
     *
     * @param  array<string, mixed>  $metadata  Optional pivot metadata
     * @param  bool  $dispatchVariations  Whether to trigger association-driven variation generation
     * @param  bool  $requirePublic  Require public visibility inside the association transaction
     */
    public function execute(
        Media $media,
        Model $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
        bool $requirePublic = false,
    ): MediaAssociation {
        $this->tenantBoundary->assertRecord($media, 'media.assets');
        $model = $this->owners->resolve($model);
        $morph_type = $model->getMorphClass();
        $morph_id = $model->getKey();

        if ($morph_id === null) {
            throw new InvalidArgumentException('Cannot attach media to an unsaved model (model key is null).');
        }

        $association = $this->mutationLock->execute($media->id, function () use (&$media, $morph_type, $morph_id, $collection, $locale, $order, $metadata, $requirePublic): MediaAssociation {
            return DB::transaction(function () use (&$media, $morph_type, $morph_id, $collection, $locale, $order, $metadata, $requirePublic): MediaAssociation {
                $media = Media::query()->lockForUpdate()->findOrFail($media->id);

                if ($requirePublic && ! $media->is_public) {
                    throw MediaNotReusableException::privateAsset($media->id);
                }

                if (! $media->isAvailable()) {
                    throw new MediaUploadException(
                        "Media [{$media->id}] cannot be associated while its status is [{$media->status->value}].",
                    );
                }

                $association = MediaAssociation::query()->firstOrNew([
                    'media_id' => $media->id,
                    'associable_type' => $morph_type,
                    'associable_id' => $morph_id,
                    'collection' => $collection,
                ]);
                $association->fill([
                    'locale' => $locale,
                    'order' => $order ?? 0,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                ]);
                if (config('tenancy.enabled') === true) {
                    $ownership = ['tenant_id' => $media->tenant_id];
                    if (array_key_exists('ownership_key', $media->getAttributes())) {
                        $ownership['ownership_key'] = $media->ownership_key;
                    }
                    $association->forceFill($ownership);
                }
                $association->save();

                return $association;
            });
        });

        // Generate any model/collection conversions that don't already exist for this media.
        if ($dispatchVariations && $model instanceof HasMedia) {
            $slotName = data_get($metadata, 'slot');
            $resolvedSlotName = is_string($slotName) && $slotName !== ''
                ? $slotName
                : $collection;

            $this->variationDispatcher->dispatchMissingForAssociation($media, $model, $resolvedSlotName);
        }

        event(MediaAttached::fromAssociation($association));

        return $association;
    }
}
