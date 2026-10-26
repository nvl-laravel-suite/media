<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\MultipartUploadGateway;

/** Deterministic provider boundary; all package receipts and scanning remain real. */
final readonly class OwnershipMultipartGateway implements MultipartUploadGateway
{
    public function __construct(private string $contents) {}

    public function initiate(array $session): void {}

    public function signPart(array $session, array $part): array
    {
        return ['partNumber' => $part['partNumber'], 'url' => 'https://storage.example.test/part', 'headers' => [], 'expiresAt' => new DateTimeImmutable('+5 minutes')];
    }

    public function complete(array $session, array $completion): array
    {
        Storage::disk($session['disk'])->put($session['objectKey'], $this->contents);

        return ['path' => $session['objectKey'], 'checksum' => hash('sha256', $this->contents), 'size' => strlen($this->contents), 'mimeType' => 'text/plain', 'objectIdentity' => 'fixture-object'];
    }

    public function abort(array $session): void {}
}
