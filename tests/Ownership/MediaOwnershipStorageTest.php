<?php

declare(strict_types=1);
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Services\MediaAssetService;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaOwnerSlotIdempotency;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Services\MediaVariations;
use Nvl\Media\Slots\MediaSlot;
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
        $updated = app(MediaLibrary::class)->updateMetadata(OwnershipActor::query()->firstOrFail(), $media, ['translations' => ['en' => ['title' => 'Owned title']]]);
        $variation = app(MediaVariations::class)->generate(OwnershipActor::query()->firstOrFail(), $updated, ConversionDefinition::fromPreset('thumb', ['width' => 16, 'height' => 16, 'format' => 'png']));
        expect($updated::class)->toBe(OwnershipMedia::class)->and($updated->translations->first()->media::class)->toBe(OwnershipMedia::class)->and($variation)->toBeInstanceOf(MediaImageVariation::class)->and($variation->getRawOriginal('owner_scope_key'))->toBe($updated->getRawOriginal('owner_scope_key'));
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
            $claim = app(MediaOwnerSlotIdempotency::class)->begin($key, $actor, OwnershipMediaScenario::owner(), 'documents', MediaOwnerSlotOperationType::Clear, []);
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
        $url = $media->buildPrivateUrl(OwnershipActor::query()->firstOrFail(), now()->addMinutes(5));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
        expect($parameters['scope'])->toBe(app(OwnershipBoundary::class)->key('media.assets', 'private-url'))->and($parameters['revision'])->toBe((string) $media->revision)->and((new Media)->resolveRouteBinding($media->id)::class)->toBe(OwnershipMedia::class);

        return $media;
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, fn () => expect((new Media)->resolveRouteBinding($asset->id))->toBeNull());
});
it('refuses an unrevocable disk fallback for owned private delivery', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $media = OwnershipMediaScenario::upload(OwnershipMediaScenario::owner());
        config()->set('nvl-media.assets.private_route_name', 'missing.owner.route');
        expect(fn () => MediaAssetUrl::privateUrl($media, [], now()->addMinute(), temporaryUrl: static fn () => 'https://unscoped-object.test/token'))->toThrow(RuntimeException::class, 'scoped signed application route');
    });
});
it('keeps retained delivery handles denied after disabling the ownership runtime', function (): void {
    $media = app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), fn () => OwnershipMediaScenario::upload(OwnershipMediaScenario::owner()));
    config()->set('nvl-ownership.enabled', false);
    expect(fn () => $media->getUrl())->toThrow(OwnershipViolation::class);
    expect(fn () => $media->getPath())->toThrow(OwnershipViolation::class);
    expect(fn () => app(MediaAssetService::class)->resolve($media))->toThrow(OwnershipViolation::class);
});

it('keeps owned replacement paths and variations aligned with the transaction outcome', function (string $outcome): void {
    config(['nvl-media.auto_generate_variations' => false, 'nvl-media.queue.enabled' => false]);
    $actor = OwnershipActor::query()->firstOrFail();
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($actor, $outcome): void {
        $definition = ConversionDefinition::fromPreset('thumb', ['width' => 16, 'height' => 16, 'format' => 'png']);
        $media = app(MediaUploader::class)->upload($actor, UploadedFile::fake()->image('source.png', 32, 32), 'public', OwnershipMediaScenario::owner(), new MediaSlot('documents'), 'source.png', skipAutoVariations: true, variationDefinitions: ['thumb' => $definition]);
        $variation = app(MediaVariations::class)->generate($actor, $media, $definition);
        $oldPath = $media->storage_path;
        $oldBytes = Storage::disk('public')->get($oldPath);
        $oldVariationPath = $variation->storage_path;
        $oldVariationBytes = Storage::disk('public')->get($oldVariationPath);
        $oldRevision = $media->revision;
        $replacementFile = UploadedFile::fake()->image('replacement.png', 48, 48);
        $replacementBytes = file_get_contents($replacementFile->getRealPath());
        DB::beginTransaction();
        if ($outcome === 'savepoint rollback') {
            DB::beginTransaction();
        }
        $replacement = app(MediaUploader::class)->replace($actor, $media, $replacementFile);
        $newPath = dirname($oldPath).'/'.$replacement->hash;
        expect($replacement->storage_path)->toBe($newPath)
            ->and(Storage::disk('public')->get($replacement->buildPath()))->toBe($replacementBytes)
            ->and($replacement->revision)->toBe($oldRevision + 1);
        Storage::disk('public')->assertExists($oldPath);
        Storage::disk('public')->assertExists($oldVariationPath);
        if ($outcome === 'commit') {
            DB::commit();
            $fresh = $media->fresh();
            $freshVariation = $fresh->imageVariations()->where('label', 'thumb')->firstOrFail();
            expect($fresh->storage_path)->toBe($newPath)
                ->and(Storage::disk('public')->get($fresh->buildPath()))->toBe($replacementBytes)
                ->and($freshVariation->source_revision)->toBe($fresh->revision)
                ->and($freshVariation->storage_path)->not->toBe($oldVariationPath);
            Storage::disk('public')->assertExists($freshVariation->storage_path);
            Storage::disk('public')->assertMissing($oldPath);
            Storage::disk('public')->assertMissing($oldVariationPath);
        } else {
            DB::rollBack();
            Storage::disk('public')->assertMissing($newPath);
            if ($outcome === 'savepoint rollback') {
                DB::commit();
            }
            $fresh = $media->fresh();
            $freshVariation = $fresh->imageVariations()->where('label', 'thumb')->firstOrFail();
            expect($fresh->storage_path)->toBe($oldPath)
                ->and($fresh->revision)->toBe($oldRevision)
                ->and(Storage::disk('public')->get($fresh->buildPath()))->toBe($oldBytes)
                ->and($freshVariation->storage_path)->toBe($oldVariationPath)
                ->and($freshVariation->source_revision)->toBe($oldRevision)
                ->and(Storage::disk('public')->get($freshVariation->storage_path))->toBe($oldVariationBytes);
        }
    });
})->with(['commit', 'rollback', 'savepoint rollback']);

it('keeps owned move paths and variation bytes aligned with the transaction outcome', function (string $outcome): void {
    $actor = OwnershipActor::query()->firstOrFail();
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($actor, $outcome): void {
        $media = OwnershipMediaScenario::upload(OwnershipMediaScenario::owner(), true);
        $variation = app(MediaVariations::class)->generate($actor, $media, ConversionDefinition::fromPreset('thumb', ['width' => 16, 'height' => 16, 'format' => 'png']));
        $oldPath = $media->storage_path;
        $oldBytes = Storage::disk('public')->get($oldPath);
        $oldVariationPath = $variation->storage_path;
        $oldVariationBytes = Storage::disk('public')->get($oldVariationPath);
        $oldRevision = $media->revision;
        DB::beginTransaction();
        if ($outcome === 'savepoint rollback') {
            DB::beginTransaction();
        }
        expect(app(MediaLibrary::class)->move($actor, [$media->id], 'moved'))->toBe(1);
        $moved = $media->fresh();
        $newPath = $moved->storage_path;
        $newVariationPath = $variation->fresh()->storage_path;
        expect($newPath)->not->toBe($oldPath)
            ->and($newPath)->toEndWith('/moved/'.$media->hash)
            ->and(Storage::disk('public')->get($moved->buildPath()))->toBe($oldBytes)
            ->and($moved->revision)->toBe($oldRevision)
            ->and(Storage::disk('public')->get($newVariationPath))->toBe($oldVariationBytes);
        Storage::disk('public')->assertExists($oldPath);
        Storage::disk('public')->assertExists($oldVariationPath);
        if ($outcome === 'commit') {
            DB::commit();
            expect(Storage::disk('public')->get($media->fresh()->buildPath()))->toBe($oldBytes)
                ->and(Storage::disk('public')->get($variation->fresh()->storage_path))->toBe($oldVariationBytes);
            Storage::disk('public')->assertMissing($oldPath);
            Storage::disk('public')->assertMissing($oldVariationPath);
        } else {
            DB::rollBack();
            Storage::disk('public')->assertMissing($newPath);
            Storage::disk('public')->assertMissing($newVariationPath);
            if ($outcome === 'savepoint rollback') {
                DB::commit();
            }
            expect($media->fresh()->storage_path)->toBe($oldPath)
                ->and(Storage::disk('public')->get($media->fresh()->buildPath()))->toBe($oldBytes)
                ->and($variation->fresh()->storage_path)->toBe($oldVariationPath)
                ->and(Storage::disk('public')->get($variation->fresh()->storage_path))->toBe($oldVariationBytes);
        }
    });
})->with(['commit', 'rollback', 'savepoint rollback']);
