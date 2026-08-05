<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Support\MediaMimeResolver;
use Throwable;

/**
 * Optimizes original image files before storage based on slot configuration.
 *
 * Handles format conversion (e.g. JPG→WebP), quality adjustment, and longest-edge
 * capping. Encodes format intelligence: skips SVG/GIF, avoids lossy same-format
 * re-encoding, and enforces dimension constraints even when processing fails.
 */
class ImageOptimizationService
{
    /**
     * @param  MediaImageTransformer  $imageTransformer  Image transformation engine
     * @param  MediaTemporaryFileRegistry  $temporaryFiles  Request-scoped temporary file registry
     */
    public function __construct(
        private readonly MediaImageTransformer $imageTransformer,
        private readonly MediaTemporaryFileRegistry $temporaryFiles,
    ) {}

    /**
     * Optimize an uploaded image file according to the slot's conversion settings.
     *
     * When optimization succeeds, returns a new UploadedFile pointing to the processed
     * temp file. When optimization fails and maxSize is set, validates the original
     * dimensions and throws if they exceed the constraint. When optimization fails
     * without maxSize, returns the original with a warning log.
     *
     * @param  UploadedFile  $file  The original uploaded file
     * @param  MediaSlot  $slot  Slot config with convertFormat, convertQuality, convertMaxSize
     * @return UploadedFile Optimized file or the original if optimization was skipped
     *
     * @throws FileUnacceptableForCollection When optimization fails and the original exceeds maxSize
     */
    public function optimize(UploadedFile $file, MediaSlot $slot): UploadedFile
    {
        if (! $this->shouldOptimize($file, $slot)) {
            return $file;
        }

        $definition = $this->buildDefinition($slot);
        $extension = strtolower($file->getClientOriginalExtension());
        $outputExtension = $definition->getResultExtension($extension);

        $tempOutput = tempnam(sys_get_temp_dir(), 'media_opt_');

        if ($tempOutput === false) {
            return $this->handleFailure($file, $slot, 'Failed to create temporary file for optimization.');
        }

        $this->temporaryFiles->track($tempOutput);
        $tempOutputWithExt = $tempOutput.'.'.$outputExtension;

        if (! rename($tempOutput, $tempOutputWithExt)) {
            $this->temporaryFiles->release($tempOutput);

            return $this->handleFailure($file, $slot, 'Failed to prepare temporary optimization output.');
        }

        $this->temporaryFiles->release($tempOutput);
        $this->temporaryFiles->track($tempOutputWithExt);

        try {
            $sourcePath = $file->getRealPath();

            if ($sourcePath === false) {
                $this->temporaryFiles->release($tempOutputWithExt);

                return $this->handleFailure($file, $slot, 'Unable to resolve uploaded image path for optimization.');
            }

            $this->imageTransformer->process($sourcePath, $tempOutputWithExt, $definition);

            $newMime = MediaMimeResolver::extensionToMime($outputExtension);
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.'.$outputExtension;

            return new UploadedFile($tempOutputWithExt, $originalName, $newMime, null, true);
        } catch (Throwable $e) {
            $this->temporaryFiles->release($tempOutputWithExt);

            return $this->handleFailure($file, $slot, $e->getMessage());
        }
    }

    /**
     * Determine whether a file should be optimized based on slot config and file type.
     *
     * Returns false for non-images, SVG, GIF, same-format re-encodes,
     * and when the slot has no optimization settings.
     *
     * @param  UploadedFile  $file  The uploaded file to check
     * @param  MediaSlot  $slot  Slot with potential optimization config
     */
    public function shouldOptimize(UploadedFile $file, MediaSlot $slot): bool
    {
        if (! $slot->shouldConvertOriginal()) {
            return false;
        }

        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $extension = strtolower($file->getClientOriginalExtension());

        // Non-image files are never optimized
        if (! str_starts_with((string) $mime, 'image/')) {
            return false;
        }

        // Check configurable skip list
        $skipFormats = (array) config('media.optimization.skip_formats', ['svg', 'gif']);

        if (in_array($extension, $skipFormats, true)) {
            return false;
        }

        // Skip same-format lossy re-encoding (WebP→WebP, JPG→JPG) when only format conversion is set
        if ($this->wouldReEncodeToSameFormat($extension, $slot->convertFormat)
            && $slot->convertMaxSize === null
            && $slot->convertQuality === null
        ) {
            return false;
        }

        return true;
    }

    /**
     * Check if the target format is the same as the source (lossy re-encode with no benefit).
     *
     * @param  string  $extension  Current file extension
     * @param  string|null  $targetFormat  Target format from slot config (null = no format change)
     * @return bool True when formats match and re-encoding would degrade quality
     */
    public function wouldReEncodeToSameFormat(string $extension, ?string $targetFormat): bool
    {
        if ($targetFormat === null) {
            return false;
        }

        $source = MimeType::fromExtension($extension);
        $target = MimeType::fromExtension($targetFormat);

        if ($source === null || $target === null) {
            return false;
        }

        return $source->isLossyReEncode($target);
    }

    /**
     * Build a ConversionDefinition from the slot's optimization settings.
     *
     * @param  MediaSlot  $slot  Slot with convertFormat, convertQuality, convertMaxSize
     */
    private function buildDefinition(MediaSlot $slot): ConversionDefinition
    {
        $definition = new ConversionDefinition('_original_optimize');

        if ($slot->convertFormat !== null) {
            $definition->format($slot->convertFormat);
        }

        if ($slot->convertQuality !== null) {
            $definition->quality($slot->convertQuality);
        }

        // maxSize: set both width and height to cap the longest edge.
        // MediaImageTransformer uses resize() with PreserveAspectRatio + DoNotUpsize
        // when both are set — this preserves ratio while capping the longest edge.
        if ($slot->convertMaxSize !== null) {
            $definition->width($slot->convertMaxSize);
            $definition->height($slot->convertMaxSize);
        }

        return $definition;
    }

    /**
     * Handle optimization failure with dimension enforcement.
     *
     * When maxSize is configured and enforcement is enabled, validates the original
     * file dimensions and throws if they exceed the constraint. Otherwise logs a
     * warning and returns the original file.
     *
     * @param  UploadedFile  $file  The original unoptimized file
     * @param  MediaSlot  $slot  Slot config for constraint checking
     * @param  string  $reason  Failure description for logging
     * @return UploadedFile The original file (when allowed to pass through)
     *
     * @throws FileUnacceptableForCollection When original exceeds maxSize and enforcement is enabled
     */
    private function handleFailure(UploadedFile $file, MediaSlot $slot, string $reason): UploadedFile
    {
        Log::warning('Image optimization failed.', [
            'filename' => $file->getClientOriginalName(),
            'slot' => $slot->name,
            'reason' => $reason,
        ]);

        // When maxSize is set, validate original dimensions — prevent oversized files
        // from silently bypassing slot rules when optimization fails.
        if ($slot->convertMaxSize !== null) {
            $realPath = $file->getRealPath();

            if ($realPath !== false) {
                $dimensions = @getimagesize($realPath);

                if ($dimensions !== false) {
                    $longestEdge = max($dimensions[0], $dimensions[1]);

                    if ($longestEdge > $slot->convertMaxSize) {
                        throw new FileUnacceptableForCollection(
                            "Image optimization failed and the original file ({$longestEdge}px) exceeds "
                            ."the maximum size ({$slot->convertMaxSize}px) for slot [{$slot->name}]."
                        );
                    }
                }
            }
        }

        return $file;
    }
}
