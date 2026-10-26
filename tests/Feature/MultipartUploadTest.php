<?php

declare(strict_types=1);
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Contracts\RecoverableMultipartUploadGateway;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Services\MediaMultipart;
use Nvl\Media\Services\MediaScan;
use Nvl\Media\Tests\Stubs\TestMediaUser;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    config(['nvl-media.multipart.enabled' => true]);
    Storage::fake('public');
});
it('leaves multipart pruning cadence to the host application', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(static fn ($event): string => (string) $event->command);
    expect($commands->contains(static fn (string $command): bool => str_contains($command, 'nvl:media:multipart:prune')))->toBeFalse();
});
function multipartGateway(array $object, bool $useReportedObjectPath = false): MultipartUploadGateway
{
    return new class($object, $useReportedObjectPath) implements MultipartUploadGateway
    {
        public ?array $initiated = null;

        public bool $aborted = false;

        public int $abortCount = 0;

        public int $completionCount = 0;

        public ?array $signedSession = null;

        public ?array $signedPart = null;

        public function __construct(private readonly array $object, private readonly bool $useReportedObjectPath) {}

        public function initiate(array $session): void
        {
            $this->initiated = $session;
        }

        public function signPart(array $session, array $part): array
        {
            $this->signedSession = $session;
            $this->signedPart = $part;

            return ['partNumber' => $part['partNumber'], 'url' => 'https://object-storage.invalid/part', 'headers' => ['x-checksum' => $part['checksum']], 'expiresAt' => new DateTimeImmutable('+5 minutes')];
        }

        public function complete(array $session, array $completion): array
        {
            $this->completionCount++;

            return ['path' => $this->useReportedObjectPath ? $this->object['path'] : $session['objectKey'], 'checksum' => $this->object['checksum'], 'size' => $this->object['size'], 'mimeType' => $this->object['mimeType'], 'objectIdentity' => 'test-object'];
        }

        public function abort(array $session): void
        {
            $this->aborted = true;
            $this->abortCount++;
        }
    };
}
function multipartActor(string $identity = 'actor-1'): Authenticatable|SystemActor
{
    return TestMediaUser::query()->firstOrCreate(['email' => $identity.'@multipart.test'], ['name' => $identity, 'password' => 'unused']);
}
function initiateMultipartSession(string $contents = 'multipart'): array
{
    $checksum = hash('sha256', $contents);
    $gateway = multipartGateway(['path' => 'ignored-by-test-gateway', 'checksum' => $checksum, 'size' => strlen($contents), 'mimeType' => 'text/plain', 'objectIdentity' => null]);
    app()->instance(MultipartUploadGateway::class, $gateway);
    $session = app(MediaMultipart::class)->initiate(['disk' => 'public', 'filename' => 'file.txt', 'mimeType' => 'text/plain', 'size' => strlen($contents), 'checksum' => $checksum, 'visibility' => MediaVisibility::Private, 'folder' => null], multipartActor());

    return [$session, $gateway, $checksum];
}
it('does not expose encrypted provider state through model serialization', function (): void {
    [$session] = initiateMultipartSession('provider-state');
    $persisted = MediaMultipartUpload::query()->findOrFail($session['uploadId']);
    $persisted->forceFill(['provider_state' => ['upload_id' => 'provider-secret']])->save();
    expect($persisted->refresh()->provider_state)->toBe(['upload_id' => 'provider-secret'])->and($persisted->toArray())->not->toHaveKey('provider_state');
});
it('initiates and completes a verified direct upload', function () {
    $checksum = hash('sha256', 'verified-object');
    $gateway = multipartGateway(['path' => 'media/pending/object-id/report.pdf', 'checksum' => $checksum, 'size' => 15, 'mimeType' => 'application/pdf', 'objectIdentity' => null]);
    app()->instance(MultipartUploadGateway::class, $gateway);
    $session = app(MediaMultipart::class)->initiate(['disk' => 'public', 'filename' => 'report.pdf', 'mimeType' => 'application/pdf', 'size' => 15, 'checksum' => $checksum, 'visibility' => MediaVisibility::Public, 'folder' => null], multipartActor());
    expect(($gateway->initiated['uploadId'] ?? null))->toBe($session['uploadId'])->and($session['objectKey'])->toEndWith('.pdf')->and($session['objectKey'])->not->toContain('report.pdf')->and(MediaMultipartUpload::query()->findOrFail($session['uploadId'])->object_key_hash)->toBe(hash('sha256', $session['objectKey']));
    app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 15], multipartActor());
    $media = app(MediaMultipart::class)->complete(['uploadId' => $session['uploadId'], 'parts' => [['partNumber' => 1, 'etag' => 'part-etag', 'checksum' => null]]], multipartActor());
    expect($media->status)->toBe(MediaLifecycleStatus::PendingScan)->and($media->is_public)->toBeTrue()->and($media->folder)->toBe('pending')->and($media->digest)->toBe($checksum)->and($media->uploaded_by_type)->toBe(TestMediaUser::class);
    Storage::disk('public')->put($session['objectKey'], 'verified-object');
    $media = app(MediaScan::class)->finalize(auth()->user() ?? SystemActor::Background, $media, ['clean' => true, 'mimeType' => 'application/pdf', 'extension' => 'pdf', 'size' => 15, 'checksum' => $checksum, 'diagnostics' => ['engine' => 'test']]);
    expect($media->status)->toBe(MediaLifecycleStatus::Available);
    $sameMedia = app(MediaMultipart::class)->complete(['uploadId' => $session['uploadId'], 'parts' => [['partNumber' => 1, 'etag' => 'part-etag', 'checksum' => null]]], multipartActor());
    expect($sameMedia->is($media))->toBeTrue()->and($gateway->completionCount)->toBe(1);
});
it('keeps multipart paths and logical folders consistent with a per-disk root', function (string $root, string $prefix): void {
    config(['nvl-media.root_folder' => 'fallback', 'nvl-media.root_folders' => ['public' => $root]]);
    [$session, $gateway, $checksum] = initiateMultipartSession('per-disk multipart');
    expect($session['objectKey'])->toStartWith($prefix.'pending/');
    app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => strlen('per-disk multipart')], multipartActor());
    $media = app(MediaMultipart::class)->complete(['uploadId' => $session['uploadId'], 'parts' => [['partNumber' => 1, 'etag' => 'etag', 'checksum' => null]]], multipartActor());
    expect($media->folder)->toBe('pending')->and($media->buildPath())->toBe($session['objectKey']);
})->with(['custom root' => ['assets/uploads', 'assets/uploads/'], 'disk root' => ['', '']]);
it('deletes and aborts a completed object when verification differs from its session', function () {
    $checksum = hash('sha256', 'expected');
    $reportedObjectPath = 'untrusted/reported-object.pdf';
    $gateway = multipartGateway(['path' => $reportedObjectPath, 'checksum' => hash('sha256', 'different'), 'size' => 9, 'mimeType' => 'application/pdf', 'objectIdentity' => null], useReportedObjectPath: true);
    app()->instance(MultipartUploadGateway::class, $gateway);
    $session = app(MediaMultipart::class)->initiate(['disk' => 'public', 'filename' => 'report.pdf', 'mimeType' => 'application/pdf', 'size' => 8, 'checksum' => $checksum, 'visibility' => MediaVisibility::Private, 'folder' => null], multipartActor());
    Storage::disk('public')->put($session['objectKey'], 'different');
    Storage::disk('public')->put($reportedObjectPath, 'unrelated-object');
    Storage::disk('public')->assertExists($session['objectKey']);
    Storage::disk('public')->assertExists($reportedObjectPath);
    app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => hash('sha256', 'part'), 'byteLength' => 8], multipartActor());
    expect(fn () => app(MediaMultipart::class)->complete(['uploadId' => $session['uploadId'], 'parts' => [['partNumber' => 1, 'etag' => 'part-etag', 'checksum' => null]]], multipartActor()))->toThrow(MediaUploadException::class, 'path, checksum, or size');
    expect($gateway->aborted)->toBeTrue();
    Storage::disk('public')->assertMissing($session['objectKey']);
    Storage::disk('public')->assertExists($reportedObjectPath);
});
it('keeps required direct uploads unavailable until a scan is finalized', function () {
    config(['nvl-media.scanner.required' => true]);
    $checksum = hash('sha256', 'scan-me');
    $gateway = multipartGateway(['path' => 'media/pending/object-id/file.txt', 'checksum' => $checksum, 'size' => 7, 'mimeType' => 'text/plain', 'objectIdentity' => null]);
    app()->instance(MultipartUploadGateway::class, $gateway);
    $session = app(MediaMultipart::class)->initiate(['disk' => 'public', 'filename' => 'file.txt', 'mimeType' => 'text/plain', 'size' => 7, 'checksum' => $checksum, 'visibility' => MediaVisibility::Private, 'folder' => null], multipartActor());
    app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 7], multipartActor());
    $media = app(MediaMultipart::class)->complete(['uploadId' => $session['uploadId'], 'parts' => [['partNumber' => 1, 'etag' => 'part-etag', 'checksum' => null]]], multipartActor());
    expect($media->status)->toBe(MediaLifecycleStatus::PendingScan)->and($media->isAvailable())->toBeFalse();
    $quarantined = app(MediaScan::class)->finalize(auth()->user() ?? SystemActor::Background, $media, ['clean' => false, 'mimeType' => 'text/plain', 'extension' => 'txt', 'size' => 7, 'checksum' => $checksum, 'diagnostics' => ['engine' => 'test', 'reason' => 'unsafe']]);
    expect($quarantined->status)->toBe(MediaLifecycleStatus::Quarantined)->and($quarantined->isAvailable())->toBeFalse()->and($quarantined->failure_context)->toBe(['engine' => 'test', 'reason' => 'unsafe']);
});
it('reloads authoritative session state from the opaque upload identifier', function () {
    [$session, $gateway, $checksum] = initiateMultipartSession('forged-state');
    app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 12], multipartActor());
    expect(($gateway->signedSession['disk'] ?? null))->toBe('public')->and(($gateway->signedSession['objectKey'] ?? null))->toBe($session['objectKey'])->and(($gateway->signedSession['filename'] ?? null))->toBe('file.txt')->and(($gateway->signedSession['uploaderId'] ?? null))->toBe((string) multipartActor()->getAuthIdentifier())->and(($gateway->signedPart['uploadId'] ?? null))->toBe($session['uploadId']);
});
it('rejects actor mismatches and invalid signed-part bounds', function () {
    [$session, , $checksum] = initiateMultipartSession('part-bounds');
    expect(fn () => app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 11], multipartActor('different-actor')))->toThrow(MediaUploadException::class, 'actor mismatch');
    expect(fn () => app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 10], multipartActor()))->toThrow(MediaUploadException::class, 'byte length');
});
it('persists expiry before refusing further part signing', function () {
    [$session, , $checksum] = initiateMultipartSession('expired');
    MediaMultipartUpload::query()->whereKey($session['uploadId'])->update(['expires_at' => now()->subMinute()]);
    expect(fn () => app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 7], multipartActor()))->toThrow(MediaUploadException::class, 'expired');
    expect(MediaMultipartUpload::query()->findOrFail($session['uploadId'])->status)->toBe(MediaMultipartStatus::Expired);
});
it('refuses persisted multipart mutations while the feature is disabled', function () {
    [$session, , $checksum] = initiateMultipartSession('disabled');
    config(['nvl-media.multipart.enabled' => false]);
    expect(fn () => app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 8], multipartActor()))->toThrow(MediaUploadException::class, 'disabled');
    expect(fn () => app(MediaMultipart::class)->abort($session['uploadId'], multipartActor()))->toThrow(MediaUploadException::class, 'disabled');
});
it('quarantines a clean scan whose technical attestation differs', function () {
    [$session, , $checksum] = initiateMultipartSession('attested');
    app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 8], multipartActor());
    $media = app(MediaMultipart::class)->complete(['uploadId' => $session['uploadId'], 'parts' => [['partNumber' => 1, 'etag' => 'part-etag', 'checksum' => null]]], multipartActor());
    Storage::disk('public')->put($session['objectKey'], 'attested');
    $media = app(MediaScan::class)->finalize(auth()->user() ?? SystemActor::Background, $media, ['clean' => true, 'mimeType' => 'text/plain', 'extension' => 'csv', 'size' => 8, 'checksum' => $checksum, 'diagnostics' => []]);
    expect($media->status)->toBe(MediaLifecycleStatus::Quarantined)->and($media->failure_code)->toBe('scan_attestation_mismatch');
});
it('recovers provider completion after an interrupted completion response', function () {
    $contents = 'recovered';
    $checksum = hash('sha256', $contents);
    $gateway = new class($checksum, strlen($contents)) implements RecoverableMultipartUploadGateway
    {
        public int $completionAttempts = 0;

        public function __construct(private readonly string $checksum, private readonly int $size) {}

        public function initiate(array $session): void {}

        public function signPart(array $session, array $part): array
        {
            return ['partNumber' => $part['partNumber'], 'url' => 'https://object-storage.invalid/part', 'headers' => [], 'expiresAt' => new DateTimeImmutable('+5 minutes')];
        }

        public function complete(array $session, array $completion): array
        {
            $this->completionAttempts++;
            throw new RuntimeException('Provider response was interrupted.');
        }

        public function inspect(array $session): ?array
        {
            return ['path' => $session['objectKey'], 'checksum' => $this->checksum, 'size' => $this->size, 'mimeType' => 'text/plain', 'objectIdentity' => 'recovered-object'];
        }

        public function abort(array $session): void {}
    };
    app()->instance(MultipartUploadGateway::class, $gateway);
    $session = app(MediaMultipart::class)->initiate(['disk' => 'public', 'filename' => 'recovered.txt', 'mimeType' => 'text/plain', 'size' => strlen($contents), 'checksum' => $checksum, 'visibility' => MediaVisibility::Private, 'folder' => null], multipartActor());
    app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => strlen($contents)], multipartActor());
    $media = app(MediaMultipart::class)->complete(['uploadId' => $session['uploadId'], 'parts' => [['partNumber' => 1, 'etag' => 'part-etag', 'checksum' => null]]], multipartActor());
    expect($media->status)->toBe(MediaLifecycleStatus::PendingScan)->and($gateway->completionAttempts)->toBe(1)->and(MediaMultipartUpload::query()->findOrFail($session['uploadId'])->provider_object_identity)->toBe('recovered-object');
});
it('prunes sessions already marked expired and records confirmed provider abort', function () {
    [$session, $gateway] = initiateMultipartSession('expired-cleanup');
    MediaMultipartUpload::query()->whereKey($session['uploadId'])->update(['status' => MediaMultipartStatus::Expired->value, 'expires_at' => now()->subMinute()]);
    $this->artisan('nvl:media:multipart:prune')->expectsOutputToContain('Cleaned multipart sessions: 1')->assertSuccessful();
    $persisted = MediaMultipartUpload::query()->findOrFail($session['uploadId']);
    expect($gateway->abortCount)->toBe(1)->and($persisted->status)->toBe(MediaMultipartStatus::Aborted)->and($persisted->failure_code)->toBe('session_expired')->and($persisted->provider_state)->toBeNull();
});
it('retries cleanup after provider initiation and its immediate compensation both fail', function () {
    $gateway = new class implements MultipartUploadGateway
    {
        public int $abortCount = 0;

        public function initiate(array $session): void
        {
            throw new RuntimeException('Provider initiation response failed.');
        }

        public function signPart(array $session, array $part): array
        {
            throw new LogicException('Signing is unavailable for a failed initiation.');
        }

        public function complete(array $session, array $completion): array
        {
            throw new LogicException('Completion is unavailable for a failed initiation.');
        }

        public function abort(array $session): void
        {
            $this->abortCount++;
            if ($this->abortCount === 1) {
                throw new RuntimeException('Immediate provider cleanup failed.');
            }
        }
    };
    app()->instance(MultipartUploadGateway::class, $gateway);
    $checksum = hash('sha256', 'initiation-cleanup');
    expect(fn () => app(MediaMultipart::class)->initiate(['disk' => 'public', 'filename' => 'cleanup.txt', 'mimeType' => 'text/plain', 'size' => 18, 'checksum' => $checksum, 'visibility' => MediaVisibility::Private, 'folder' => null], multipartActor()))->toThrow(RuntimeException::class, 'Provider initiation response failed.');
    $persisted = MediaMultipartUpload::query()->sole();
    expect($persisted->status)->toBe(MediaMultipartStatus::Failed)->and($persisted->failure_code)->toBe('provider_initiation_cleanup_pending')->and($persisted->expires_at->isFuture())->toBeFalse()->and($gateway->abortCount)->toBe(1);
    $this->artisan('nvl:media:multipart:prune')->expectsOutputToContain('Cleaned multipart sessions: 1')->assertSuccessful();
    $persisted->refresh();
    expect($gateway->abortCount)->toBe(2)->and($persisted->status)->toBe(MediaMultipartStatus::Aborted)->and($persisted->failure_code)->toBe('provider_initiation_failed');
});
it('can retry local abort persistence after the provider has already accepted the abort', function () {
    [$session, $gateway] = initiateMultipartSession('abort-retry');
    $failPersistence = true;
    MediaMultipartUpload::saving(function (MediaMultipartUpload $persisted) use (&$failPersistence): void {
        if ($failPersistence && $persisted->isDirty('status') && $persisted->status === MediaMultipartStatus::Aborted) {
            $failPersistence = false;
            throw new RuntimeException('Local abort persistence failed.');
        }
    });
    expect(fn () => app(MediaMultipart::class)->abort($session['uploadId'], multipartActor()))->toThrow(RuntimeException::class, 'Local abort persistence failed.');
    expect(MediaMultipartUpload::query()->findOrFail($session['uploadId'])->status)->toBe(MediaMultipartStatus::Initiated)->and($gateway->abortCount)->toBe(1);
    app(MediaMultipart::class)->abort($session['uploadId'], multipartActor());
    $persisted = MediaMultipartUpload::query()->findOrFail($session['uploadId']);
    expect($gateway->abortCount)->toBe(2)->and($persisted->status)->toBe(MediaMultipartStatus::Aborted)->and($persisted->provider_state)->toBeNull();
});
