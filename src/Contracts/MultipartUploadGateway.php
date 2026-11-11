<?php

declare(strict_types=1);

namespace Nvl\Media\Contracts;

use Nvl\Media\Enums\MediaVisibility;

/**
 * Object-storage adapter for direct multipart uploads.
 *
 * @api
 */
interface MultipartUploadGateway
{
    /**
     * Initiate a provider multipart upload.
     *
     * @param  array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int}  $session
     */
    public function initiate(array $session): void;

    /**
     * Sign one part upload.
     *
     * @param  array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int}  $session
     * @param  array{uploadId: string, partNumber: int, checksum: string, byteLength: int}  $part
     * @return array{partNumber: int, url: string, headers: array<string, string>, expiresAt: \DateTimeImmutable}
     */
    public function signPart(
        array $session,
        array $part,
    ): array;

    /**
     * Complete and server-verify an uploaded object.
     *
     * `checksum` is the provider-attested lowercase SHA-256 of the whole object, or null when the provider
     * cannot attest one without reading the object; scan finalisation then verifies the stored bytes.
     *
     * @param  array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int}  $session
     * @param  array{uploadId: string, parts: list<array{partNumber: int, etag: string, checksum?: string|null}>}  $completion
     * @return array{path: string, checksum: string|null, size: int, mimeType: string, objectIdentity: string|null}
     */
    public function complete(
        array $session,
        array $completion,
    ): array;

    /**
     * Idempotently abort an unfinished provider upload, treating provider absence as success.
     *
     * @param  array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: int|string|null, uploaderType: string|null, expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int}  $session
     */
    public function abort(array $session): void;
}
