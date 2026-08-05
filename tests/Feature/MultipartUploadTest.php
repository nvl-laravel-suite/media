<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\AbortMultipartUploadAction;
use Nvl\Media\Actions\CompleteMultipartUploadAction;
use Nvl\Media\Actions\FinalizeMediaScanAction;
use Nvl\Media\Actions\InitiateMultipartUploadAction;
use Nvl\Media\Actions\SignMultipartPartAction;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Contracts\RecoverableMultipartUploadGateway;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\MediaScanResultData;
use Nvl\Media\Data\Multipart\CompletedMultipartObjectData;
use Nvl\Media\Data\Multipart\CompletedMultipartPartData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\InitiateMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Tests\Stubs\TestMediaUser;

beforeEach(function (): void {
    config(['media.multipart.enabled' => true]);
    Storage::fake('public');
});

function multipartGateway(
    CompletedMultipartObjectData $object,
    bool $useReportedObjectPath = false,
): MultipartUploadGateway {
    return new class($object, $useReportedObjectPath) implements MultipartUploadGateway
    {
        public ?MultipartUploadSessionData $initiated = null;

        public bool $aborted = false;

        public int $abortCount = 0;

        public int $completionCount = 0;

        public ?MultipartUploadSessionData $signedSession = null;

        public ?SignMultipartPartData $signedPart = null;

        public function __construct(
            private readonly CompletedMultipartObjectData $object,
            private readonly bool $useReportedObjectPath,
        ) {}

        public function initiate(MultipartUploadSessionData $session): void
        {
            $this->initiated = $session;
        }

        public function signPart(
            MultipartUploadSessionData $session,
            SignMultipartPartData $part,
        ): SignedMultipartPartData {
            $this->signedSession = $session;
            $this->signedPart = $part;

            return new SignedMultipartPartData(
                partNumber: $part->partNumber,
                url: 'https://object-storage.invalid/part',
                headers: ['x-checksum' => $part->checksum],
                expiresAt: new DateTimeImmutable('+5 minutes'),
            );
        }

        public function complete(
            MultipartUploadSessionData $session,
            CompleteMultipartUploadData $completion,
        ): CompletedMultipartObjectData {
            $this->completionCount++;

            return new CompletedMultipartObjectData(
                path: $this->useReportedObjectPath
                    ? $this->object->path
                    : $session->objectKey,
                checksum: $this->object->checksum,
                size: $this->object->size,
                mimeType: $this->object->mimeType,
                objectIdentity: 'test-object',
            );
        }

        public function abort(MultipartUploadSessionData $session): void
        {
            $this->aborted = true;
            $this->abortCount++;
        }
    };
}

function multipartActor(): MediaActorData
{
    return new MediaActorData(TestMediaUser::class, 'actor-1');
}

function initiateMultipartSession(string $contents = 'multipart'): array
{
    $checksum = hash('sha256', $contents);
    $gateway = multipartGateway(new CompletedMultipartObjectData(
        path: 'ignored-by-test-gateway',
        checksum: $checksum,
        size: strlen($contents),
        mimeType: 'text/plain',
    ));
    app()->instance(MultipartUploadGateway::class, $gateway);
    $session = app(InitiateMultipartUploadAction::class)->execute(
        new InitiateMultipartUploadData(
            disk: 'public',
            filename: 'file.txt',
            mimeType: 'text/plain',
            size: strlen($contents),
            checksum: $checksum,
        ),
        multipartActor(),
    );

    return [$session, $gateway, $checksum];
}

it('does not expose encrypted provider state through model serialization', function (): void {
    [$session] = initiateMultipartSession('provider-state');
    $persisted = MediaMultipartUpload::query()->findOrFail($session->uploadId);
    $persisted->forceFill([
        'provider_state' => ['upload_id' => 'provider-secret'],
    ])->save();

    expect($persisted->refresh()->provider_state)->toBe([
        'upload_id' => 'provider-secret',
    ])->and($persisted->toArray())->not->toHaveKey('provider_state');
});

it('initiates and completes a verified direct upload', function () {
    $checksum = hash('sha256', 'verified-object');
    $gateway = multipartGateway(new CompletedMultipartObjectData(
        path: 'media/pending/object-id/report.pdf',
        checksum: $checksum,
        size: 15,
        mimeType: 'application/pdf',
    ));
    app()->instance(MultipartUploadGateway::class, $gateway);

    $session = app(InitiateMultipartUploadAction::class)->execute(
        new InitiateMultipartUploadData(
            disk: 'public',
            filename: 'report.pdf',
            mimeType: 'application/pdf',
            size: 15,
            checksum: $checksum,
            visibility: MediaVisibility::Public,
        ),
        multipartActor(),
    );

    expect($gateway->initiated?->uploadId)->toBe($session->uploadId)
        ->and($session->objectKey)->toEndWith('.pdf')
        ->and($session->objectKey)->not->toContain('report.pdf')
        ->and(MediaMultipartUpload::query()
            ->findOrFail($session->uploadId)
            ->object_key_hash)
        ->toBe(hash('sha256', $session->objectKey));

    app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, $checksum, 15),
        multipartActor(),
    );

    $media = app(CompleteMultipartUploadAction::class)->execute(
        new CompleteMultipartUploadData(
            uploadId: $session->uploadId,
            parts: [new CompletedMultipartPartData(1, 'part-etag')],
        ),
        multipartActor(),
    );

    expect($media->status)->toBe(MediaLifecycleStatus::PendingScan)
        ->and($media->is_public)->toBeTrue()
        ->and($media->folder)->toBe('pending')
        ->and($media->digest)->toBe($checksum)
        ->and($media->uploaded_by_type)->toBe(TestMediaUser::class);

    Storage::disk('public')->put($session->objectKey, 'verified-object');
    $media = app(FinalizeMediaScanAction::class)->execute(
        $media,
        new MediaScanResultData(
            clean: true,
            mimeType: 'application/pdf',
            extension: 'pdf',
            size: 15,
            checksum: $checksum,
            diagnostics: ['engine' => 'test'],
        ),
    );

    expect($media->status)->toBe(MediaLifecycleStatus::Available);

    $sameMedia = app(CompleteMultipartUploadAction::class)->execute(
        new CompleteMultipartUploadData(
            uploadId: $session->uploadId,
            parts: [new CompletedMultipartPartData(1, 'part-etag')],
        ),
        multipartActor(),
    );

    expect($sameMedia->is($media))->toBeTrue()
        ->and($gateway->completionCount)->toBe(1);
});

it('deletes and aborts a completed object when verification differs from its session', function () {
    $checksum = hash('sha256', 'expected');
    $reportedObjectPath = 'untrusted/reported-object.pdf';
    $gateway = multipartGateway(new CompletedMultipartObjectData(
        path: $reportedObjectPath,
        checksum: hash('sha256', 'different'),
        size: 9,
        mimeType: 'application/pdf',
    ), useReportedObjectPath: true);
    app()->instance(MultipartUploadGateway::class, $gateway);

    $session = app(InitiateMultipartUploadAction::class)->execute(
        new InitiateMultipartUploadData(
            disk: 'public',
            filename: 'report.pdf',
            mimeType: 'application/pdf',
            size: 8,
            checksum: $checksum,
        ),
        multipartActor(),
    );
    Storage::disk('public')->put($session->objectKey, 'different');
    Storage::disk('public')->put($reportedObjectPath, 'unrelated-object');
    Storage::disk('public')->assertExists($session->objectKey);
    Storage::disk('public')->assertExists($reportedObjectPath);

    app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, hash('sha256', 'part'), 8),
        multipartActor(),
    );

    expect(fn () => app(CompleteMultipartUploadAction::class)->execute(
        new CompleteMultipartUploadData(
            $session->uploadId,
            [new CompletedMultipartPartData(1, 'part-etag')],
        ),
        multipartActor(),
    ))->toThrow(MediaUploadException::class, 'path, checksum, or size');

    expect($gateway->aborted)->toBeTrue();
    Storage::disk('public')->assertMissing($session->objectKey);
    Storage::disk('public')->assertExists($reportedObjectPath);
});

it('keeps required direct uploads unavailable until a scan is finalized', function () {
    config(['media.scanner.required' => true]);
    $checksum = hash('sha256', 'scan-me');
    $gateway = multipartGateway(new CompletedMultipartObjectData(
        path: 'media/pending/object-id/file.txt',
        checksum: $checksum,
        size: 7,
        mimeType: 'text/plain',
    ));
    app()->instance(MultipartUploadGateway::class, $gateway);

    $session = app(InitiateMultipartUploadAction::class)->execute(
        new InitiateMultipartUploadData(
            disk: 'public',
            filename: 'file.txt',
            mimeType: 'text/plain',
            size: 7,
            checksum: $checksum,
        ),
        multipartActor(),
    );
    app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, $checksum, 7),
        multipartActor(),
    );
    $media = app(CompleteMultipartUploadAction::class)->execute(
        new CompleteMultipartUploadData(
            $session->uploadId,
            [new CompletedMultipartPartData(1, 'part-etag')],
        ),
        multipartActor(),
    );

    expect($media->status)->toBe(MediaLifecycleStatus::PendingScan)
        ->and($media->isAvailable())->toBeFalse();

    $quarantined = app(FinalizeMediaScanAction::class)->execute(
        $media,
        new MediaScanResultData(
            clean: false,
            mimeType: 'text/plain',
            extension: 'txt',
            size: 7,
            checksum: $checksum,
            diagnostics: ['engine' => 'test', 'reason' => 'unsafe'],
        ),
    );

    expect($quarantined->status)->toBe(MediaLifecycleStatus::Quarantined)
        ->and($quarantined->isAvailable())->toBeFalse()
        ->and($quarantined->failure_context)->toBe([
            'engine' => 'test',
            'reason' => 'unsafe',
        ]);
});

it('reloads authoritative session state from the opaque upload identifier', function () {
    [$session, $gateway, $checksum] = initiateMultipartSession('forged-state');

    app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, $checksum, 12),
        multipartActor(),
    );

    expect($gateway->signedSession?->disk)->toBe('public')
        ->and($gateway->signedSession?->objectKey)->toBe($session->objectKey)
        ->and($gateway->signedSession?->filename)->toBe('file.txt')
        ->and($gateway->signedSession?->uploaderId)->toBe('actor-1')
        ->and($gateway->signedPart?->uploadId)->toBe($session->uploadId);
});

it('rejects actor mismatches and invalid signed-part bounds', function () {
    [$session, , $checksum] = initiateMultipartSession('part-bounds');

    expect(fn () => app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, $checksum, 11),
        new MediaActorData(TestMediaUser::class, 'different-actor'),
    ))->toThrow(MediaUploadException::class, 'actor mismatch');

    expect(fn () => app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, $checksum, 10),
        multipartActor(),
    ))->toThrow(MediaUploadException::class, 'byte length');
});

it('persists expiry before refusing further part signing', function () {
    [$session, , $checksum] = initiateMultipartSession('expired');
    MediaMultipartUpload::query()
        ->whereKey($session->uploadId)
        ->update(['expires_at' => now()->subMinute()]);

    expect(fn () => app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, $checksum, 7),
        multipartActor(),
    ))->toThrow(MediaUploadException::class, 'expired');

    expect(MediaMultipartUpload::query()->findOrFail($session->uploadId)->status)
        ->toBe(MediaMultipartStatus::Expired);
});

it('refuses persisted multipart mutations while the feature is disabled', function () {
    [$session, , $checksum] = initiateMultipartSession('disabled');
    config(['media.multipart.enabled' => false]);

    expect(fn () => app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, $checksum, 8),
        multipartActor(),
    ))->toThrow(MediaUploadException::class, 'disabled');

    expect(fn () => app(AbortMultipartUploadAction::class)->execute(
        $session->uploadId,
        multipartActor(),
    ))->toThrow(MediaUploadException::class, 'disabled');
});

it('quarantines a clean scan whose technical attestation differs', function () {
    [$session, , $checksum] = initiateMultipartSession('attested');
    app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, $checksum, 8),
        multipartActor(),
    );
    $media = app(CompleteMultipartUploadAction::class)->execute(
        new CompleteMultipartUploadData(
            $session->uploadId,
            [new CompletedMultipartPartData(1, 'part-etag')],
        ),
        multipartActor(),
    );
    Storage::disk('public')->put($session->objectKey, 'attested');

    $media = app(FinalizeMediaScanAction::class)->execute(
        $media,
        new MediaScanResultData(
            clean: true,
            mimeType: 'text/plain',
            extension: 'csv',
            size: 8,
            checksum: $checksum,
        ),
    );

    expect($media->status)->toBe(MediaLifecycleStatus::Quarantined)
        ->and($media->failure_code)->toBe('scan_attestation_mismatch');
});

it('recovers provider completion after an interrupted completion response', function () {
    $contents = 'recovered';
    $checksum = hash('sha256', $contents);
    $gateway = new class($checksum, strlen($contents)) implements RecoverableMultipartUploadGateway
    {
        public int $completionAttempts = 0;

        public function __construct(
            private readonly string $checksum,
            private readonly int $size,
        ) {}

        public function initiate(MultipartUploadSessionData $session): void {}

        public function signPart(
            MultipartUploadSessionData $session,
            SignMultipartPartData $part,
        ): SignedMultipartPartData {
            return new SignedMultipartPartData(
                $part->partNumber,
                'https://object-storage.invalid/part',
                [],
                new DateTimeImmutable('+5 minutes'),
            );
        }

        public function complete(
            MultipartUploadSessionData $session,
            CompleteMultipartUploadData $completion,
        ): CompletedMultipartObjectData {
            $this->completionAttempts++;

            throw new RuntimeException('Provider response was interrupted.');
        }

        public function inspect(MultipartUploadSessionData $session): ?CompletedMultipartObjectData
        {
            return new CompletedMultipartObjectData(
                path: $session->objectKey,
                checksum: $this->checksum,
                size: $this->size,
                mimeType: 'text/plain',
                objectIdentity: 'recovered-object',
            );
        }

        public function abort(MultipartUploadSessionData $session): void {}
    };
    app()->instance(MultipartUploadGateway::class, $gateway);
    $session = app(InitiateMultipartUploadAction::class)->execute(
        new InitiateMultipartUploadData(
            disk: 'public',
            filename: 'recovered.txt',
            mimeType: 'text/plain',
            size: strlen($contents),
            checksum: $checksum,
        ),
        multipartActor(),
    );
    app(SignMultipartPartAction::class)->execute(
        new SignMultipartPartData($session->uploadId, 1, $checksum, strlen($contents)),
        multipartActor(),
    );

    $media = app(CompleteMultipartUploadAction::class)->execute(
        new CompleteMultipartUploadData(
            $session->uploadId,
            [new CompletedMultipartPartData(1, 'part-etag')],
        ),
        multipartActor(),
    );

    expect($media->status)->toBe(MediaLifecycleStatus::PendingScan)
        ->and($gateway->completionAttempts)->toBe(1)
        ->and(MediaMultipartUpload::query()->findOrFail($session->uploadId)->provider_object_identity)
        ->toBe('recovered-object');
});

it('prunes sessions already marked expired and records confirmed provider abort', function () {
    [$session, $gateway] = initiateMultipartSession('expired-cleanup');
    MediaMultipartUpload::query()
        ->whereKey($session->uploadId)
        ->update([
            'status' => MediaMultipartStatus::Expired->value,
            'expires_at' => now()->subMinute(),
        ]);

    $this->artisan('nvl:media:multipart:prune')
        ->expectsOutputToContain('Cleaned multipart sessions: 1')
        ->assertSuccessful();

    $persisted = MediaMultipartUpload::query()->findOrFail($session->uploadId);

    expect($gateway->abortCount)->toBe(1)
        ->and($persisted->status)->toBe(MediaMultipartStatus::Aborted)
        ->and($persisted->failure_code)->toBe('session_expired')
        ->and($persisted->provider_state)->toBeNull();
});

it('retries cleanup after provider initiation and its immediate compensation both fail', function () {
    $gateway = new class implements MultipartUploadGateway
    {
        public int $abortCount = 0;

        public function initiate(MultipartUploadSessionData $session): void
        {
            throw new RuntimeException('Provider initiation response failed.');
        }

        public function signPart(
            MultipartUploadSessionData $session,
            SignMultipartPartData $part,
        ): SignedMultipartPartData {
            throw new LogicException('Signing is unavailable for a failed initiation.');
        }

        public function complete(
            MultipartUploadSessionData $session,
            CompleteMultipartUploadData $completion,
        ): CompletedMultipartObjectData {
            throw new LogicException('Completion is unavailable for a failed initiation.');
        }

        public function abort(MultipartUploadSessionData $session): void
        {
            $this->abortCount++;

            if ($this->abortCount === 1) {
                throw new RuntimeException('Immediate provider cleanup failed.');
            }
        }
    };
    app()->instance(MultipartUploadGateway::class, $gateway);
    $checksum = hash('sha256', 'initiation-cleanup');

    expect(fn () => app(InitiateMultipartUploadAction::class)->execute(
        new InitiateMultipartUploadData(
            disk: 'public',
            filename: 'cleanup.txt',
            mimeType: 'text/plain',
            size: 18,
            checksum: $checksum,
        ),
        multipartActor(),
    ))->toThrow(RuntimeException::class, 'Provider initiation response failed.');

    $persisted = MediaMultipartUpload::query()->sole();

    expect($persisted->status)->toBe(MediaMultipartStatus::Failed)
        ->and($persisted->failure_code)->toBe('provider_initiation_cleanup_pending')
        ->and($persisted->expires_at->isFuture())->toBeFalse()
        ->and($gateway->abortCount)->toBe(1);

    $this->artisan('nvl:media:multipart:prune')
        ->expectsOutputToContain('Cleaned multipart sessions: 1')
        ->assertSuccessful();

    $persisted->refresh();

    expect($gateway->abortCount)->toBe(2)
        ->and($persisted->status)->toBe(MediaMultipartStatus::Aborted)
        ->and($persisted->failure_code)->toBe('provider_initiation_failed');
});

it('can retry local abort persistence after the provider has already accepted the abort', function () {
    [$session, $gateway] = initiateMultipartSession('abort-retry');
    $failPersistence = true;

    MediaMultipartUpload::saving(
        function (MediaMultipartUpload $persisted) use (&$failPersistence): void {
            if ($failPersistence
                && $persisted->isDirty('status')
                && $persisted->status === MediaMultipartStatus::Aborted) {
                $failPersistence = false;

                throw new RuntimeException('Local abort persistence failed.');
            }
        },
    );

    expect(fn () => app(AbortMultipartUploadAction::class)->execute(
        $session->uploadId,
        multipartActor(),
    ))->toThrow(RuntimeException::class, 'Local abort persistence failed.');

    expect(MediaMultipartUpload::query()->findOrFail($session->uploadId)->status)
        ->toBe(MediaMultipartStatus::Initiated)
        ->and($gateway->abortCount)->toBe(1);

    app(AbortMultipartUploadAction::class)->execute(
        $session->uploadId,
        multipartActor(),
    );

    $persisted = MediaMultipartUpload::query()->findOrFail($session->uploadId);

    expect($gateway->abortCount)->toBe(2)
        ->and($persisted->status)->toBe(MediaMultipartStatus::Aborted)
        ->and($persisted->provider_state)->toBeNull();
});
