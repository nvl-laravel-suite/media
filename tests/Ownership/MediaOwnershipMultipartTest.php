<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaMultipart;
use Nvl\Media\Services\MediaScan;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\Fixtures\OwnershipMedia;
use Nvl\Media\Tests\Fixtures\OwnershipMultipartGateway;
use Nvl\Media\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;

uses(OwnershipTestCase::class, OptsIntoAdoptedSchemaReuse::class);
it('keeps multipart receipts completion and scanning in the captured native company', function (): void {
    Storage::fake('public');
    config(['nvl-media.multipart.enabled' => true]);
    app()->instance(MultipartUploadGateway::class, new OwnershipMultipartGateway('multipart'));
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $producer = $actor;
    $checksum = hash('sha256', 'multipart');
    $session = $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($producer, $checksum) {
        return app(MediaMultipart::class)->initiate(['disk' => 'public', 'filename' => 'source.txt', 'mimeType' => 'text/plain', 'size' => 9, 'checksum' => $checksum, 'visibility' => MediaVisibility::Private, 'folder' => null], $producer);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, fn () => expect(fn () => app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 9], $producer))->toThrow(ModelNotFoundException::class));
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($session, $checksum, $producer): void {
        app(MediaMultipart::class)->signPart(['uploadId' => $session['uploadId'], 'partNumber' => 1, 'checksum' => $checksum, 'byteLength' => 9], $producer);
        $completion = ['uploadId' => $session['uploadId'], 'parts' => [['partNumber' => 1, 'etag' => 'etag', 'checksum' => null]]];
        $media = app(MediaMultipart::class)->complete($completion, $producer);
        expect($media::class)->toBe(OwnershipMedia::class)->and($media->status)->toBe(MediaLifecycleStatus::PendingScan);
        $media = app(MediaScan::class)->finalize(OwnershipActor::query()->firstOrFail(), $media, ['clean' => true, 'mimeType' => 'text/plain', 'extension' => 'txt', 'size' => 9, 'checksum' => $checksum, 'diagnostics' => []]);
        expect($media->status)->toBe(MediaLifecycleStatus::Available)->and(app(MediaMultipart::class)->complete($completion, $producer)->id)->toBe($media->id)->and(MediaModelRegistry::query()->count())->toBe(1);
    });
});
