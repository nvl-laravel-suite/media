<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Data\Ingestion\ValidatedMediaFileData;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Support\Logging\PackageLogger;
use Throwable;

/**
 * Authoritative validation, scanner, and SVG boundary for materialized media.
 */
final class MediaIngestionPipeline
{
    public function __construct(
        private readonly MediaUploadValidator $validator,
        private readonly MediaContentScanner $contentScanner,
        private readonly MediaScannerPolicy $scannerPolicy,
        private readonly SvgScanner $svgScanner,
        private PackageLogger $packageLogger,
    ) {}

    /**
     * Validate and scan a source against every slot that will reference it.
     *
     * @param  non-empty-list<MediaSlot>  $slots
     */
    public function inspect(
        UploadedFile $file,
        array $slots,
        ?string $displayFilename = null,
    ): ValidatedMediaFileData {
        $primarySlot = $slots[0];
        $validatedFile = $this->validator->validate(
            $file,
            $primarySlot,
            $displayFilename,
        );

        foreach (array_slice($slots, 1) as $slot) {
            $this->validator->assertAcceptedBySlot($validatedFile, $file, $slot);
        }

        try {
            $this->scannerPolicy->assertReady();
            $this->contentScanner->scan($file);

            if ($validatedFile->extension === 'svg'
                || $validatedFile->mimeType === 'image/svg+xml') {
                $this->svgScanner->scan($validatedFile->realPath);
            }
        } catch (Throwable $exception) {
            $this->packageLogger->log('media', 'warning', 'nvl.media.ingestion_scan.rejected', [
                'filename' => $validatedFile->displayFilename,
                'mime_type' => $validatedFile->mimeType,
                'size' => $validatedFile->size,
                'scanner' => $this->contentScanner::class,
                'exception' => $exception::class,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ], 'normal');

            throw $exception;
        }

        return $validatedFile;
    }
}
