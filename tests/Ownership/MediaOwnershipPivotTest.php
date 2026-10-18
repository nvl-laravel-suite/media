<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\Fixtures\OwnershipMediaScenario;
use Nvl\Media\Tests\Fixtures\PivotOwnershipMedia;
use Nvl\Media\Tests\PivotMediaTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(PivotMediaTestCase::class);

it('persists the configured asset and exclusive native ownership pivot atomically', function (): void {
    Storage::fake('public');
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $foreign = null;
    foreach ([7, 8] as $id) {
        $runner->run(OwnershipCompany::query()->findOrFail($id), $actor, function () use ($id, &$foreign): void {
            $owner = OwnershipMediaScenario::owner();
            $media = OwnershipMediaScenario::upload($owner);
            expect($media::class)->toBe(PivotOwnershipMedia::class)
                ->and(DB::table('media_company_ownership')->where('asset_id', $media->id)->value('company_id'))->toBe($id)
                ->and(MediaModelRegistry::query()->count())->toBe(1);
            app(AttachMediaAction::class)->execute($media, $owner, dispatchVariations: false);
            if ($id === 7) {
                $foreign = $media;
            } else {
                expect(fn () => $foreign->getUrl())->toThrow(OwnershipViolation::class);
            }
            DB::beginTransaction();
            $rolledBack = OwnershipMediaScenario::upload($owner, deduplicateExisting: false);
            DB::rollBack();
            expect(DB::table('media_company_ownership')->where('asset_id', $rolledBack->id)->exists())->toBeFalse();
        });
    }
});
