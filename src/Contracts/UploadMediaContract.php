<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;

interface UploadMediaContract
{
    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $metadata
     * @param  array<string, ConversionDefinition|array<string, mixed>>  $variationDefinitions
     */
    public function execute(
        UploadedFile $file,
        string $disk,
        Model $model,
        MediaSlot $slot,
        string $fileName,
        bool $isPublic = false,
        array $tags = [],
        array $metadata = [],
        ?string $folderOverride = null,
        ?bool $allowDuplicates = null,
        ?bool $deduplicateExisting = null,
        bool $skipAutoVariations = false,
        ?string $uploadedBy = null,
        ?string $uploadedByType = null,
        array $variationDefinitions = [],
    ): Media;
}
