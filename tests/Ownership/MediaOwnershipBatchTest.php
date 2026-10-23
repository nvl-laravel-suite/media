<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Contracts\ListOwnerMediaContract;
use Nvl\Media\Contracts\ListOwnerMediaSlotsContract;
use Nvl\Media\Contracts\MediaBatchAuthorization;
use Nvl\Media\Contracts\RemoveOwnerMediaContract;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\Fixtures\OwnershipMediaScenario;
use Nvl\Media\Tests\OwnershipTestCase;
use Nvl\Media\Tests\Stubs\BatchMediaAuthorization;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

uses(OwnershipTestCase::class);

it('keeps complete owner media pages and removal inside the admitted native owner', function (): void {
    app()->instance(MediaBatchAuthorization::class, new BatchMediaAuthorization);
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    [$owner, $media] = $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function (): array {
        $owner = OwnershipMediaScenario::owner();
        $media = OwnershipMediaScenario::upload($owner);
        app(AttachMediaAction::class)->execute($media, $owner, 'documents', dispatchVariations: false);

        return [$owner, $media];
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, function () use ($owner, $media): void {
        expect(fn () => app(ListOwnerMediaContract::class)->execute(MediaActorData::system(), $owner))
            ->toThrow(TenantBoundaryViolation::class)
            ->and(fn () => app(RemoveOwnerMediaContract::class)->execute(MediaActorData::system(), $owner, $media->id, 'documents'))
            ->toThrow(TenantBoundaryViolation::class);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($owner, $media): void {
        $page = app(ListOwnerMediaContract::class)->execute(MediaActorData::system(), $owner);
        expect($page->total)->toBe(1)->and($page->distinctAssetCount)->toBe(1)
            ->and($page->originalBytes)->toBe($media->size)
            ->and($page->items[0]->media->mediaId)->toBe($media->id);
        app(RemoveOwnerMediaContract::class)->execute(MediaActorData::system(), $owner, $media->id, 'documents');
        expect(app(ListOwnerMediaContract::class)->execute(MediaActorData::system(), $owner)->total)->toBe(0);
    });
});

it('holds owned public batch delivery queries fixed for one twenty five and one hundred owners', function (): void {
    app()->instance(MediaBatchAuthorization::class, new BatchMediaAuthorization);
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $owners = [];
        for ($index = 0; $index < 100; $index++) {
            $owner = OwnershipMediaScenario::owner();
            $asset = MediaModelRegistry::create(['filename' => 'public.txt', 'hash' => $owner->id.'.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'size' => 5, 'disk' => 'public', 'folder' => '', 'storage_path' => 'media/public/'.$owner->id.'.txt', 'type' => MediaType::DOCUMENT, 'digest' => hash('sha256', 'bytes'), 'is_public' => true, 'visibility' => 'public', 'revision' => 1]);
            app(AttachMediaAction::class)->execute($asset, $owner, 'documents', dispatchVariations: false);
            $owners[] = $owner;
        }
        $counts = [];
        foreach ([1, 25, 100] as $count) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = app(ListOwnerMediaSlotsContract::class)->execute(array_slice($owners, 0, $count), ['documents'], MediaActorData::system());
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            expect($result->order)->toHaveCount($count);
        }
        expect($counts[1])->toBe($counts[0])->and($counts[2])->toBe($counts[0]);
    });
});
