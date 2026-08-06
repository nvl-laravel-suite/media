<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Support\Facades\Log;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaImageDriver;
use Nvl\Media\Exceptions\ConversionFailedException;
use Spatie\Image\Enums\AlignPosition;
use Spatie\Image\Enums\Constraint;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\FlipDirection;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Image;
use Throwable;

/**
 * Transforms local image files into configured Media conversion outputs.
 *
 * Owns the low-level Spatie Image manipulation pipeline for Media originals,
 * variations, and optimization flows. Storage, database writes, and URL
 * behavior remain outside this service.
 */
class MediaImageTransformer
{
    /**
     * Transform an image with the given conversion definition.
     *
     * @param  string  $sourcePath  Absolute path to source image
     * @param  string  $outputPath  Absolute path for output image
     * @param  ConversionDefinition  $definition  Conversion settings
     * @return array{width: int, height: int, size: int} Resulting dimensions and file size
     *
     * @throws ConversionFailedException When processing fails
     */
    public function process(string $sourcePath, string $outputPath, ConversionDefinition $definition): array
    {
        try {
            $this->ensureOutputDirectory($outputPath);
            $startTime = microtime(true);

            $driver = MediaImageDriver::resolve(config('media.image_driver', MediaImageDriver::Gd));
            $image = Image::useImageDriver($driver->spatieDriver())->loadFile($sourcePath);
            $image = $this->applyManipulations($image, $definition);
            $this->applyOutputSettings($image, $definition);
            $image->save($outputPath);

            if (! file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new ConversionFailedException("Image save produced empty or missing output at [{$outputPath}].");
            }

            // Read dimensions from file headers only to avoid a full image decode.
            $dimensions = @getimagesize($outputPath);
            $width = $dimensions[0] ?? 0;
            $height = $dimensions[1] ?? 0;
            $size = filesize($outputPath);

            if ($size === false) {
                throw new ConversionFailedException("Unable to determine file size of processed image at [{$outputPath}].");
            }

            $duration = round((microtime(true) - $startTime) * 1000, 1);
            Log::debug('Image processed.', [
                'source' => basename($sourcePath),
                'output' => basename($outputPath),
                'conversion' => $definition->name,
                'width' => $width,
                'height' => $height,
                'size' => $size,
                'duration_ms' => $duration,
            ]);

            return [
                'width' => $width,
                'height' => $height,
                'size' => $size,
            ];
        } catch (Throwable $e) {
            if ($e instanceof ConversionFailedException) {
                throw $e;
            }

            throw new ConversionFailedException(
                "Image processing failed: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * Apply all spatial and colour manipulations.
     *
     * @param  Image  $image  Loaded image instance
     * @param  ConversionDefinition  $definition  Conversion settings
     * @return Image Manipulated image instance
     */
    private function applyManipulations(Image $image, ConversionDefinition $definition): Image
    {
        $this->applyRotation($image, $definition);
        $this->applyFlip($image, $definition);
        $this->applyResize($image, $definition);
        $this->applyColourAdjustments($image, $definition);
        $this->applyWatermark($image, $definition);
        $this->applyBackground($image, $definition);

        return $image;
    }

    /**
     * Apply rotation if specified.
     *
     * @param  Image  $image  Loaded image instance
     * @param  ConversionDefinition  $definition  Conversion settings
     */
    private function applyRotation(Image $image, ConversionDefinition $definition): void
    {
        if ($definition->rotationDegrees === null) {
            return;
        }

        foreach (Orientation::cases() as $orientation) {
            if ($orientation->degrees() === $definition->rotationDegrees) {
                $image->orientation($orientation);

                return;
            }
        }
    }

    /**
     * Apply flip if specified.
     *
     * @param  Image  $image  Loaded image instance
     * @param  ConversionDefinition  $definition  Conversion settings
     */
    private function applyFlip(Image $image, ConversionDefinition $definition): void
    {
        if ($definition->flipDirection === null) {
            return;
        }

        $direction = match ($definition->flipDirection) {
            'h' => FlipDirection::Horizontal,
            'v' => FlipDirection::Vertical,
            default => null,
        };

        if ($direction !== null) {
            $image->flip($direction);
        }
    }

    /**
     * Apply resize/crop/fit operations.
     *
     * @param  Image  $image  Loaded image instance
     * @param  ConversionDefinition  $definition  Conversion settings
     */
    private function applyResize(Image $image, ConversionDefinition $definition): void
    {
        if ($definition->cropWidth !== null && $definition->cropHeight !== null) {
            $image->fit(Fit::Crop, $definition->cropWidth, $definition->cropHeight);
        } elseif ($definition->fitMethod !== null && $definition->fitWidth !== null && $definition->fitHeight !== null) {
            $this->applyFit($image, $definition);
        } elseif ($definition->targetWidth !== null && $definition->targetHeight !== null) {
            $image->resize(
                $definition->targetWidth,
                $definition->targetHeight,
                [Constraint::PreserveAspectRatio, Constraint::DoNotUpsize],
            );
        } elseif ($definition->targetWidth !== null) {
            $image->width($definition->targetWidth, [Constraint::PreserveAspectRatio, Constraint::DoNotUpsize]);
        } elseif ($definition->targetHeight !== null) {
            $image->height($definition->targetHeight, [Constraint::PreserveAspectRatio, Constraint::DoNotUpsize]);
        }
    }

    /**
     * Apply a named fit method.
     *
     * @param  Image  $image  Loaded image instance
     * @param  ConversionDefinition  $definition  Conversion settings
     */
    private function applyFit(Image $image, ConversionDefinition $definition): void
    {
        $fit = match ($definition->fitMethod) {
            'crop' => Fit::Crop,
            'contain' => Fit::Contain,
            'stretch' => Fit::Stretch,
            'max' => Fit::Max,
            default => Fit::Contain,
        };

        $image->fit($fit, $definition->fitWidth, $definition->fitHeight);
    }

    /**
     * Apply brightness, contrast, greyscale, sharpen, and blur.
     *
     * @param  Image  $image  Loaded image instance
     * @param  ConversionDefinition  $definition  Conversion settings
     */
    private function applyColourAdjustments(Image $image, ConversionDefinition $definition): void
    {
        if ($definition->brightnessAmount !== null) {
            $image->brightness($definition->brightnessAmount);
        }

        if ($definition->contrastAmount !== null) {
            $image->contrast((float) $definition->contrastAmount);
        }

        if ($definition->applyGreyscale) {
            $image->greyscale();
        }

        if ($definition->sharpenAmount !== null) {
            $image->sharpen((float) $definition->sharpenAmount);
        }

        if ($definition->blurAmount !== null) {
            $image->blur($definition->blurAmount);
        }
    }

    /**
     * Apply a watermark overlay if configured.
     *
     * @param  Image  $image  Loaded image instance
     * @param  ConversionDefinition  $definition  Conversion settings
     */
    private function applyWatermark(Image $image, ConversionDefinition $definition): void
    {
        if ($definition->watermarkPath === null || ! file_exists($definition->watermarkPath)) {
            return;
        }

        $position = match ($definition->watermarkPosition) {
            'top-left' => AlignPosition::TopLeft,
            'top-right' => AlignPosition::TopRight,
            'bottom-left' => AlignPosition::BottomLeft,
            'center' => AlignPosition::Center,
            default => AlignPosition::BottomRight,
        };

        $image->watermark(
            $definition->watermarkPath,
            position: $position,
            paddingX: 10,
            paddingY: 10,
        );
    }

    /**
     * Apply background colour by inserting onto a canvas.
     *
     * @param  Image  $image  Loaded image instance
     * @param  ConversionDefinition  $definition  Conversion settings
     */
    private function applyBackground(Image $image, ConversionDefinition $definition): void
    {
        if ($definition->backgroundColor === null) {
            return;
        }

        $image->background($definition->backgroundColor);
    }

    /**
     * Apply output format and quality settings.
     *
     * @param  Image  $image  Loaded image instance
     * @param  ConversionDefinition  $definition  Conversion settings
     */
    private function applyOutputSettings(Image $image, ConversionDefinition $definition): void
    {
        if ($definition->outputFormat !== null) {
            $image->format($definition->outputFormat);
        }

        $quality = $definition->targetQuality ?? 85;
        $image->quality($quality);
    }

    /**
     * Ensure the output directory exists.
     *
     * @param  string  $outputPath  Absolute path for output image
     */
    private function ensureOutputDirectory(string $outputPath): void
    {
        $output_dir = dirname($outputPath);

        if (! is_dir($output_dir)) {
            mkdir($output_dir, 0755, true);
        }
    }
}
