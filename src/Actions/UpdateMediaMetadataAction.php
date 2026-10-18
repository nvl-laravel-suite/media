<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Nvl\Media\Contracts\UpdateMediaMetadataContract;
use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Events\MediaMutated;
use Nvl\Media\Exceptions\MediaInUseException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Translatable\Services\TranslationWriter;
use Spatie\LaravelData\Optional;

/**
 * Updates mutable media metadata and localized text fields atomically.
 *
 * @api
 */
final readonly class UpdateMediaMetadataAction implements UpdateMediaMetadataContract
{
    /**
     * Create the media metadata action.
     */
    public function __construct(
        private TranslationWriter $translations,
        private MediaMutationLock $mutationLock,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Update metadata and translation fields for a media record.
     */
    public function execute(Media|string $media, UpdateMediaPayload $data): Media
    {
        $mediaId = $media instanceof Media ? $media->id : $media;
        $freshMedia = $this->mutationLock->execute($mediaId, function () use ($mediaId, $data): Media {
            return MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($mediaId, $data): Media {
                $media = MediaModelRegistry::query()->lockForUpdate()->findOrFail($mediaId);
                $mediaFields = [];

                if (! $data->tags instanceof Optional) {
                    $mediaFields['tags'] = $data->tags;
                }

                if (! $data->metadata instanceof Optional) {
                    $mediaFields['metadata'] = $data->metadata;
                }

                if (! $data->isPublic instanceof Optional) {
                    if (! $data->isPublic && $media->is_public && $media->associations()->count() > 1) {
                        throw MediaInUseException::privateVisibility($media->id);
                    }

                    $mediaFields['is_public'] = $data->isPublic;
                }

                if ($mediaFields !== []) {
                    $media->update($mediaFields);
                }

                $translationRows = [];

                if (! $data->translations instanceof Optional && is_array($data->translations)) {
                    foreach ($data->translations as $locale => $attributes) {
                        $translationRows[$locale] = $attributes;
                    }
                }

                if ($translationRows !== [] || $data->translationMode->value === 'replace') {
                    $this->translations->sync(
                        $media,
                        $translationRows,
                        $data->translationMode,
                    );
                }

                $this->domainEvents->dispatch(new MediaMutated($media->id, 'metadata_updated'), $media->getConnection());

                return MediaModelRegistry::query()
                    ->with(['imageVariations', 'translations'])
                    ->findOrFail($media->id);
            });
        });

        return $freshMedia;
    }
}
