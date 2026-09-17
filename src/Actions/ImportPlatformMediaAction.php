<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Data\Mutations\ImportPlatformMediaData;
use Nvl\Media\Models\Media;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Throwable;

/** Standalone facade around Media's staged catalog transaction port. */
final readonly class ImportPlatformMediaAction
{
    public function __construct(private MediaCatalogImport $imports, private EffectiveTenantConnection $connections) {}

    public function execute(ImportPlatformMediaData $data): Media
    {
        $snapshot = $this->imports->inspect($data->grantId);
        if ($snapshot->grantRevision !== $data->expectedGrantRevision
            || $snapshot->sourceRevision !== $data->expectedSourceRevision) {
            throw new TenantBoundaryViolation('The requested Media catalog revision is stale.');
        }
        $file = $this->imports->stage($snapshot);
        try {
            return $this->connections->core()->transaction(
                fn (): Media => $this->imports->persist($snapshot, $file, $data->idempotencyKey),
            );
        } catch (Throwable $exception) {
            $this->imports->discard($file);
            throw $exception;
        }
    }
}
