<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nvl\Media\Data\MediaScanResultData;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaFileTypePolicy;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaVariationDispatcher;

/**
 * Finalizes an out-of-band scan using scanner-attested technical metadata.
 */
final class FinalizeMediaScanAction
{
    public function __construct(
        private readonly MediaDiskGateway $disks,
        private readonly MediaFileTypePolicy $fileTypes,
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaVariationDispatcher $variationDispatcher,
    ) {}

    public function execute(
        Media|string $media,
        MediaScanResultData $result,
    ): Media {
        $id = $media instanceof Media ? $media->id : $media;

        return $this->mutationLock->execute($id, function () use ($id, $result): Media {
            $finalized = DB::transaction(function () use ($id, $result): Media {
                $locked = Media::query()->lockForUpdate()->findOrFail($id);

                if ($locked->status === MediaLifecycleStatus::Available && $result->clean) {
                    return $locked;
                }

                if ($locked->status === MediaLifecycleStatus::Quarantined && ! $result->clean) {
                    return $locked;
                }

                if ($locked->status !== MediaLifecycleStatus::PendingScan) {
                    throw new MediaUploadException(
                        "Media [{$locked->id}] is not awaiting a scan result.",
                    );
                }

                if (! $result->clean) {
                    $this->quarantine(
                        $locked,
                        'scan_rejected',
                        $result->diagnostics,
                    );

                    return $locked;
                }

                $technicalMismatch = $this->technicalMismatch($locked, $result);

                if ($technicalMismatch !== null) {
                    $this->quarantine(
                        $locked,
                        'scan_attestation_mismatch',
                        [
                            'mismatch' => $technicalMismatch,
                            ...$result->diagnostics,
                        ],
                    );

                    return $locked;
                }

                $fileType = $this->fileTypes->resolve(
                    $locked->filename,
                    $result->mimeType,
                );
                $locked->forceFill([
                    'extension' => $fileType->extension,
                    'mime_type' => $fileType->mimeType,
                    'size' => $result->size,
                    'type' => $fileType->type,
                    'digest' => $result->checksum,
                    'status' => MediaLifecycleStatus::Available,
                    'available_at' => now(),
                    'quarantined_at' => null,
                    'failure_code' => null,
                    'failure_context' => null,
                ])->save();

                return $locked;
            });

            if ($finalized->status === MediaLifecycleStatus::Available) {
                $this->variationDispatcher->dispatchForCurrentState($finalized);
            }

            return $finalized;
        });
    }

    private function technicalMismatch(
        Media $media,
        MediaScanResultData $result,
    ): ?string {
        if ($result->size !== $media->size) {
            return 'declared_size';
        }

        if (! hash_equals($media->digest, mb_strtolower($result->checksum))) {
            return 'declared_checksum';
        }

        if (mb_strtolower($result->extension) !== $media->extension) {
            return 'declared_extension';
        }

        if ($this->disks->size($media->disk, $media->buildPath()) !== $result->size) {
            return 'stored_size';
        }

        if (! hash_equals(
            mb_strtolower($result->checksum),
            $this->disks->checksum($media->disk, $media->buildPath()),
        )) {
            return 'stored_checksum';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function quarantine(Media $media, string $code, array $context): void
    {
        $media->forceFill([
            'status' => MediaLifecycleStatus::Quarantined,
            'available_at' => null,
            'quarantined_at' => now(),
            'failure_code' => $code,
            'failure_context' => $context,
        ])->save();

        Log::warning('Media scanner quarantined a direct upload.', [
            'media_id' => $media->id,
            'failure_code' => $code,
            'diagnostics' => $context,
        ]);
    }
}
