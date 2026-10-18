<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\CompleteMultipartUploadAction;
use Nvl\Media\Actions\FinalizeMediaScanAction;
use Nvl\Media\Actions\InitiateMultipartUploadAction;
use Nvl\Media\Actions\SignMultipartPartAction;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\MediaScanResultData;
use Nvl\Media\Data\Multipart\CompletedMultipartPartData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\InitiateMultipartUploadData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\Fixtures\OwnershipMedia;
use Nvl\Media\Tests\Fixtures\OwnershipMultipartGateway;
use Nvl\Media\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;

uses(OwnershipTestCase::class);

it('keeps multipart receipts completion and scanning in the captured native company', function (): void {
    Storage::fake('public');
    config(['nvl-media.multipart.enabled' => true]);
    app()->instance(MultipartUploadGateway::class, new OwnershipMultipartGateway('multipart'));
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $producer = MediaActorData::fromAuthenticatable($actor);
    $checksum = hash('sha256', 'multipart');
    $session = $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($producer, $checksum) {
        return app(InitiateMultipartUploadAction::class)->execute(new InitiateMultipartUploadData('public', 'source.txt', 'text/plain', 9, $checksum), $producer);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, fn () => expect(fn () => app(SignMultipartPartAction::class)->execute(new SignMultipartPartData($session->uploadId, 1, $checksum, 9), $producer))->toThrow(ModelNotFoundException::class));
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($session, $checksum, $producer): void {
        app(SignMultipartPartAction::class)->execute(new SignMultipartPartData($session->uploadId, 1, $checksum, 9), $producer);
        $completion = new CompleteMultipartUploadData($session->uploadId, [new CompletedMultipartPartData(1, 'etag')]);
        $media = app(CompleteMultipartUploadAction::class)->execute($completion, $producer);
        expect($media::class)->toBe(OwnershipMedia::class)->and($media->status)->toBe(MediaLifecycleStatus::PendingScan);
        $media = app(FinalizeMediaScanAction::class)->execute($media, new MediaScanResultData(true, 'text/plain', 'txt', 9, $checksum));
        expect($media->status)->toBe(MediaLifecycleStatus::Available)
            ->and(app(CompleteMultipartUploadAction::class)->execute($completion, $producer)->id)->toBe($media->id)
            ->and(MediaModelRegistry::query()->count())->toBe(1);
    });
});
