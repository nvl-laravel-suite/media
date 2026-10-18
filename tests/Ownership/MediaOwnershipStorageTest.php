<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Actions\UpdateMediaMetadataAction;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Services\MediaAssetService;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaOwnerSlotIdempotency;
use Nvl\Media\Support\MediaAssetUrl;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\Fixtures\OwnershipMedia;
use Nvl\Media\Tests\Fixtures\OwnershipMediaScenario;
use Nvl\Media\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipTestCase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('removes the owned row pivot assignment and object on a real outer rollback', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $owner = OwnershipMediaScenario::owner();
        DB::beginTransaction();
        $media = OwnershipMediaScenario::upload($owner);
        $path = $media->storage_path;
        Storage::disk('public')->assertExists($path);
        DB::rollBack();
        expect(MediaModelRegistry::query()->count())->toBe(0);
        Storage::disk('public')->assertMissing($path);
    });
});

it('uses inherited ownership for configured translations and native image variations', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $media = OwnershipMediaScenario::upload(OwnershipMediaScenario::owner(), true);
        $updated = app(UpdateMediaMetadataAction::class)->execute($media, UpdateMediaPayload::from(['translations' => ['en' => ['title' => 'Owned title']]]));
        $variation = app(GenerateImageVariationAction::class)->execute($updated, ConversionDefinition::fromPreset('thumb', ['width' => 16, 'height' => 16, 'format' => 'png']));
        expect($updated::class)->toBe(OwnershipMedia::class)
            ->and($updated->translations->first()->media::class)->toBe(OwnershipMedia::class)
            ->and($variation)->toBeInstanceOf(MediaImageVariation::class)
            ->and($variation->getRawOriginal('owner_scope_key'))->toBe($updated->getRawOriginal('owner_scope_key'));
        Storage::disk('public')->assertExists($variation->storage_path);
    });
});

it('runs native captured variation work under the producer and restores its surrounding owner', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $media = null;
    $job = $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use (&$media): GenerateImageVariationJob {
        $media = OwnershipMediaScenario::upload(OwnershipMediaScenario::owner(), true);

        return new GenerateImageVariationJob($media->id, 'queued', ['width' => 12, 'height' => 12, 'format' => 'png'], $media->revision);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, function () use ($job): void {
        app(Dispatcher::class)->dispatchSync($job);
        expect(MediaModelRegistry::query()->count())->toBe(0)->and(MediaImageVariation::query()->count())->toBe(0);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => expect(MediaImageVariation::query()->where('media_id', $media->id)->count())->toBe(1));
});

it('partitions the same receipt key and refuses another owners carried claim', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $key = (string) Str::uuid();
    $foreign = null;
    foreach ([7, 8] as $id) {
        $runner->run(OwnershipCompany::query()->findOrFail($id), $actor, function () use ($id, $key, &$foreign, $actor): void {
            $claim = app(MediaOwnerSlotIdempotency::class)->begin($key, MediaActorData::fromAuthenticatable($actor), OwnershipMediaScenario::owner(), 'documents', MediaOwnerSlotOperationType::Clear, []);
            expect(MediaOwnerSlotOperation::query()->count())->toBe(1);
            if ($id === 7) {
                $foreign = $claim;
            } else {
                expect(fn () => app(MediaOwnerSlotIdempotency::class)->complete($foreign, null))->toThrow(LogicException::class);
            }
        });
    }
});

it('rejects forged delivery storage even when carried owner facts are canonical', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $media = OwnershipMediaScenario::upload(OwnershipMediaScenario::owner());
        $media->setRawAttributes([...$media->getAttributes(), 'storage_path' => 'media/owners/foreign/secret.txt'], true);
        expect(fn () => $media->getUrl())->toThrow(OwnershipViolation::class);
    });
});

it('binds private capabilities to ownership generation revision and exact configured route binding', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $asset = $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () {
        $media = OwnershipMediaScenario::upload(OwnershipMediaScenario::owner());
        $url = $media->getUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
        expect($parameters['scope'])->toBe(app(OwnershipBoundary::class)->key('media.assets', 'private-url'))
            ->and($parameters['revision'])->toBe((string) $media->revision)
            ->and((new Media)->resolveRouteBinding($media->id)::class)->toBe(OwnershipMedia::class);

        return $media;
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, fn () => expect((new Media)->resolveRouteBinding($asset->id))->toBeNull());
});

it('refuses an unrevocable disk fallback for owned private delivery', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $media = OwnershipMediaScenario::upload(OwnershipMediaScenario::owner());
        config()->set('nvl-media.assets.private_route_name', 'missing.owner.route');
        expect(fn () => MediaAssetUrl::privateUrl($media, temporaryUrl: static fn () => 'https://unscoped-object.test/token'))->toThrow(RuntimeException::class, 'scoped signed application route');
    });
});

it('keeps retained delivery handles denied after disabling the ownership runtime', function (): void {
    $media = app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), fn () => OwnershipMediaScenario::upload(OwnershipMediaScenario::owner()));
    config()->set('nvl-ownership.enabled', false);
    expect(fn () => $media->getUrl())->toThrow(OwnershipViolation::class);
    expect(fn () => $media->getPath())->toThrow(OwnershipViolation::class);
    expect(fn () => app(MediaAssetService::class)->resolve($media))->toThrow(OwnershipViolation::class);
});
