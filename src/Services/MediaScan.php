<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Support\MediaActors;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Logging\PackageLogger;

/**
 * Complete native MediaScan operations, including persistence and file effects.
 *
 * @api
 */
final class MediaScan
{
    public function __construct(
        private readonly MediaAccessService $access,
        private readonly MediaDiskGateway $disks,
        private readonly MediaFileTypePolicy $fileTypes,
        private readonly MediaMutationLock $mutationLock,
        private readonly MediaVariationDispatcher $variationDispatcher,
        private readonly PackageLogger $packageLogger,
    ) {}

    /** Apply a scanner's bound attestation to its pending asset.
     * @param  array<string, mixed>  $result
     */
    public function finalize(Authenticatable|SystemActor $actor,
        Media|string $media,
        array $result,
    ): Media {
        if (array_diff(array_keys($result), ['clean', 'mimeType', 'extension', 'size', 'checksum', 'diagnostics']) !== []
            || ! is_bool($result['clean'] ?? null) || ! is_string($result['mimeType'] ?? null)
            || ! is_string($result['extension'] ?? null) || ! is_int($result['size'] ?? null) || $result['size'] < 0
            || ! is_string($result['checksum'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $result['checksum']) !== 1
            || (array_key_exists('diagnostics', $result) && ! is_array($result['diagnostics']))) {
            throw new MediaUploadException('Scanner attestations must contain valid native metadata.');
        }
        $diagnostics = $result['diagnostics'] ?? [];
        $diagnosticMap = [];
        foreach ($diagnostics as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                throw new MediaUploadException('Scanner diagnostics must be a map of scalar values.');
            }
            $diagnosticMap[$key] = $value;
        }
        $result = [
            'clean' => $result['clean'], 'mimeType' => $result['mimeType'],
            'extension' => $result['extension'], 'size' => $result['size'],
            'checksum' => $result['checksum'], 'diagnostics' => $diagnosticMap,
        ];

        $actor = MediaActors::assert($actor);
        $this->access->authorize($actor, MediaAbility::Mutate, MediaModelRegistry::query()->findOrFail($media instanceof Media ? $media->id : $media));
        $id = $media instanceof Media ? $media->id : $media;

        return $this->mutationLock->execute($id, function () use ($actor, $id, $result): Media {
            $finalized = MediaModelRegistry::newInstance()->getConnection()->transaction(function () use ($actor, $id, $result): Media {
                $locked = MediaModelRegistry::query()->lockForUpdate()->findOrFail($id);

                if ($locked->status === MediaLifecycleStatus::Available && $result['clean']) {
                    return $locked;
                }

                if ($locked->status === MediaLifecycleStatus::Quarantined && ! $result['clean']) {
                    return $locked;
                }

                if ($locked->status !== MediaLifecycleStatus::PendingScan) {
                    throw new MediaUploadException(
                        "Media [{$locked->id}] is not awaiting a scan result.",
                    );
                }

                if (! $result['clean']) {
                    $this->finalizeQuarantine($actor,
                        $locked,
                        'scan_rejected',
                        $result['diagnostics'],
                    );

                    return $locked;
                }

                $technicalMismatch = $this->finalizeTechnicalMismatch($actor, $locked, $result);

                if ($technicalMismatch !== null) {
                    $this->finalizeQuarantine($actor,
                        $locked,
                        'scan_attestation_mismatch',
                        [
                            'mismatch' => $technicalMismatch,
                            ...$result['diagnostics'],
                        ],
                    );

                    return $locked;
                }

                $fileType = $this->fileTypes->resolve(
                    $locked->filename,
                    $result['mimeType'],
                );
                $locked->forceFill([
                    'extension' => $fileType['extension'],
                    'mime_type' => $fileType['mimeType'],
                    'size' => $result['size'],
                    'type' => $fileType['type'],
                    'digest' => $result['checksum'],
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

    /**
     * @param  array{clean: bool, mimeType: string, extension: string, size: int, checksum: string, diagnostics: array<array-key, mixed>}  $result
     */
    private function finalizeTechnicalMismatch(Authenticatable|SystemActor $actor,
        Media $media,
        array $result,
    ): ?string {
        if ($result['size'] !== $media->size) {
            return 'declared_size';
        }

        if (! hash_equals($media->digest, mb_strtolower($result['checksum']))) {
            return 'declared_checksum';
        }

        if (mb_strtolower($result['extension']) !== $media->extension) {
            return 'declared_extension';
        }

        if ($this->disks->size($media->disk, $media->buildPath()) !== $result['size']) {
            return 'stored_size';
        }

        if (! hash_equals(
            mb_strtolower($result['checksum']),
            $this->disks->checksum($media->disk, $media->buildPath()),
        )) {
            return 'stored_checksum';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function finalizeQuarantine(Authenticatable|SystemActor $actor, Media $media, string $code, array $context): void
    {
        $media->forceFill([
            'status' => MediaLifecycleStatus::Quarantined,
            'available_at' => null,
            'quarantined_at' => now(),
            'failure_code' => $code,
            'failure_context' => $context,
        ])->save();

        $this->packageLogger->log('media', 'warning', 'nvl.media.direct_scan.quarantined', [
            'media_id' => $media->id,
            'failure_code' => $code,
            'diagnostics' => $context,
        ], 'normal');
    }
}
