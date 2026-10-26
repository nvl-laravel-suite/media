<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Consumer\Services;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaScan;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Services\MediaVariations;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translatable\Enums\TranslationSyncMode;

/**
 * Coordinates the package's focused media mutation actions for public consumers.
 */
final readonly class MediaMutationService
{
    public function __construct(
        private MediaLibrary $deleteMedia,
        private MediaUploader $replaceMedia,
        private MediaLibrary $renameMedia,
        private MediaLibrary $relocateMedia,
        private MediaLibrary $updateMedia,
        private MediaVariations $generateVariation,
        private MediaScan $finalizeScan,
    ) {}

    public function delete(Media|string $media, bool $force = false): bool
    {
        return $this->deleteMedia->delete(auth()->user() ?? SystemActor::Background, $media, $force);
    }

    public function replace(Media|string $media, UploadedFile $file): Media
    {
        return $this->replaceMedia->replace(auth()->user() ?? SystemActor::Background, $media, $file);
    }

    public function rename(Media|string $media, string $filename): Media
    {
        return $this->renameMedia->rename(auth()->user() ?? SystemActor::Background, $media, $filename);
    }

    /**
     * Relocate one media record and its variations to another disk.
     */
    public function relocate(
        Media|string $media,
        string $disk,
        MediaVisibility $visibility,
        ?int $expectedRevision = null,
    ): Media {
        return $this->relocateMedia->relocate(auth()->user() ?? SystemActor::Background, $media, $disk, $visibility, $expectedRevision);
    }

    public function update(Media|string $media, array $data): Media
    {
        if (array_key_exists('is_public', $data)) {
            $data['isPublic'] = $data['is_public'];
            unset($data['is_public']);
        }
        if (isset($data['translationMode']) && is_string($data['translationMode'])) {
            $data['translationMode'] = TranslationSyncMode::from($data['translationMode']);
        }

        return $this->updateMedia->updateMetadata(auth()->user() ?? SystemActor::Background, $media, $data);
    }

    public function generateVariation(
        Media $media,
        ConversionDefinition $definition,
        ?int $expectedRevision = null,
    ): ?MediaImageVariation {
        return $this->generateVariation->generate(auth()->user() ?? SystemActor::Background, $media, $definition, $expectedRevision);
    }

    public function finalizeScan(Media|string $media, array $result): Media
    {
        return $this->finalizeScan->finalize(auth()->user() ?? SystemActor::Background, $media, $result);
    }
}
