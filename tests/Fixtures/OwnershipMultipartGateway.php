<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Data\Multipart\CompletedMultipartObjectData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;

/** Deterministic provider boundary; all package receipts and scanning remain real. */
final readonly class OwnershipMultipartGateway implements MultipartUploadGateway
{
    public function __construct(private string $contents) {}

    public function initiate(MultipartUploadSessionData $session): void {}

    public function signPart(MultipartUploadSessionData $session, SignMultipartPartData $part): SignedMultipartPartData
    {
        return new SignedMultipartPartData($part->partNumber, 'https://storage.example.test/part', [], new DateTimeImmutable('+5 minutes'));
    }

    public function complete(MultipartUploadSessionData $session, CompleteMultipartUploadData $completion): CompletedMultipartObjectData
    {
        Storage::disk($session->disk)->put($session->objectKey, $this->contents);

        return new CompletedMultipartObjectData($session->objectKey, hash('sha256', $this->contents), strlen($this->contents), 'text/plain', 'fixture-object');
    }

    public function abort(MultipartUploadSessionData $session): void {}
}
