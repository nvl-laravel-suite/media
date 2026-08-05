<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Events\MediaAttached;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaVariationDispatcher;

/**
 * Attaches a media record to a model via a polymorphic pivot with deduplication.
 */
final class AttachMediaAction implements AttachMediaContract
{
    public function __construct(
        private readonly MediaVariationDispatcher $variationDispatcher,
        private readonly MediaMutationLock $mutationLock,
    ) {}

    /**
     * Attach a Media record to a model via a polymorphic pivot, deduplicating by composite key.
     *
     * After attaching, any model/collection-level conversions that don't already
     * exist as variations for this media will be generated.
     *
     * @param  array<string, mixed>  $metadata  Optional pivot metadata
     * @param  bool  $dispatchVariations  Whether to trigger association-driven variation generation
     */
    public function execute(
        Media $media,
        Model $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): MediaAssociation {
        if (! $media->isAvailable()) {
            throw new MediaUploadException(
                "Media [{$media->id}] cannot be associated while its status is [{$media->status->value}].",
            );
        }

        $morph_type = $model->getMorphClass();
        $morph_id = $model->getKey();

        if ($morph_id === null) {
            throw new InvalidArgumentException('Cannot attach media to an unsaved model (model key is null).');
        }

        $association = $this->mutationLock->execute($media->id, function () use ($media, $morph_type, $morph_id, $collection, $locale, $order, $metadata): MediaAssociation {
            return DB::transaction(function () use ($media, $morph_type, $morph_id, $collection, $locale, $order, $metadata): MediaAssociation {
                Media::query()->lockForUpdate()->findOrFail($media->id);

                return MediaAssociation::updateOrCreate(
                    [
                        'media_id' => $media->id,
                        'associable_type' => $morph_type,
                        'associable_id' => $morph_id,
                        'collection' => $collection,
                    ],
                    [
                        'locale' => $locale,
                        'order' => $order ?? 0,
                        'metadata' => ! empty($metadata) ? $metadata : null,
                    ],
                );
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
