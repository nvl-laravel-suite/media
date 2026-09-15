<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Contracts\ReusePublicMediaContract;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/**
 * Reuses a stored public asset without uploading or copying its physical file.
 *
 * Delegation to AttachMediaAction is deliberate action composition so reused
 * assets retain canonical association and variation behavior.
 */
final readonly class ReusePublicMediaAction implements ReusePublicMediaContract
{
    /**
     * Create the serialized public media reuse action.
     */
    public function __construct(
        private AttachMediaAction $attachMedia,
    ) {}

    /**
     * Attach an asset only while its locked, persisted state permits public reuse.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Media|string $media,
        Model&HasMedia $model,
        string $collection = 'default',
        ?string $locale = null,
        ?int $order = null,
        array $metadata = [],
        bool $dispatchVariations = true,
    ): MediaAssociation {
        $resolvedMedia = is_string($media)
            ? Media::query()->findOrFail($media)
            : $media;

        return $this->attachMedia->execute(
            media: $resolvedMedia,
            model: $model,
            collection: $collection,
            locale: $locale,
            order: $order,
            metadata: $metadata,
            dispatchVariations: $dispatchVariations,
            requirePublic: true,
        );
    }
}
