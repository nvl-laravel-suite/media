<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Actions\FinalizeMediaScanAction;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Actions\RenameMediaAction;
use Nvl\Media\Actions\ReplaceMediaFileAction;
use Nvl\Media\Actions\UpdateMediaMetadataAction;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Data\MediaScanResultData;
use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;

/**
 * Coordinates the package's focused media mutation actions for public consumers.
 */
final readonly class MediaMutationService
{
    public function __construct(
        private DeleteMediaContract $deleteMedia,
        private ReplaceMediaFileAction $replaceMedia,
        private RenameMediaAction $renameMedia,
        private UpdateMediaMetadataAction $updateMedia,
        private GenerateImageVariationAction $generateVariation,
        private FinalizeMediaScanAction $finalizeScan,
    ) {}

    public function delete(Media|string $media, bool $force = false): bool
    {
        return $this->deleteMedia->execute($media, $force);
    }

    public function replace(Media|string $media, UploadedFile $file): Media
    {
        return $this->replaceMedia->execute($media, $file);
    }

    public function rename(Media|string $media, string $filename): Media
    {
        return $this->renameMedia->execute($media, $filename);
    }

    public function update(Media|string $media, UpdateMediaPayload $data): Media
    {
        return $this->updateMedia->execute($media, $data);
    }

    public function generateVariation(
        Media $media,
        ConversionDefinition $definition,
        ?int $expectedRevision = null,
    ): ?MediaImageVariation {
        return $this->generateVariation->execute($media, $definition, $expectedRevision);
    }

    public function finalizeScan(Media|string $media, MediaScanResultData $result): Media
    {
        return $this->finalizeScan->execute($media, $result);
    }
}
