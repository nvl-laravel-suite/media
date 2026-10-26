<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Media\Models\Media;
use Nvl\Support\Enums\SystemActor;

/**
 * Public staged transaction port for composing Media copies into consumer graphs.
 *
 * @api
 */
interface MediaCatalogImport
{
    /** @return array{grantId: string, grantRevision: int, sourceId: string, sourceRevision: int, sourceDigest: string, disk: string, storagePath: string, filename: string, extension: string, mimeType: string, size: int, metadata: array<string, mixed>, tags: list<string>, translations: array<string, array<string, string|null>>} */
    public function inspect(Authenticatable|SystemActor $actor, string $grantId): array;

    /** @param array{grantId: string, grantRevision: int, sourceId: string, sourceRevision: int, sourceDigest: string, disk: string, storagePath: string, filename: string, extension: string, mimeType: string, size: int, metadata: array<string, mixed>, tags: list<string>, translations: array<string, array<string, string|null>>} $source
     * @return array{operationId: string, disk: string, storagePath: string, digest: string, size: int, ownerScopeKey: string}
     */
    public function stage(Authenticatable|SystemActor $actor, array $source): array;

    /** @param array{grantId: string, grantRevision: int, sourceId: string, sourceRevision: int, sourceDigest: string, disk: string, storagePath: string, filename: string, extension: string, mimeType: string, size: int, metadata: array<string, mixed>, tags: list<string>, translations: array<string, array<string, string|null>>} $source
     * @param  array{operationId: string, disk: string, storagePath: string, digest: string, size: int, ownerScopeKey: string}  $file
     */
    public function persist(Authenticatable|SystemActor $actor, array $source, array $file, string $idempotencyKey): Media;

    /** @param array{operationId: string, disk: string, storagePath: string, digest: string, size: int, ownerScopeKey: string} $file */
    public function discard(Authenticatable|SystemActor $actor, array $file): void;
}
